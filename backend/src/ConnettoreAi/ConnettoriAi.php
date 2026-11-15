<?php

namespace App\ConnettoreAi;

use App\Account\Entity\User;
use App\Account\TwoFactor\TwoFactorService;
use App\ConnettoreAi\Entity\ConnettoreAi;
use App\ConnettoreAi\Entity\ConnettoreAiRichiesta;
use App\ConnettoreAi\Repository\ConnettoreAiRepository;
use App\ConnettoreAi\Repository\ConnettoreAiRichiestaRepository;
use App\ConnettoreAi\Repository\LavoroAiRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\NonTrovato;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Abbinamento, token e revoca dei connettori AI (docs/connettore-ai.md):
 * OAuth 2.0 Device Authorization Grant (RFC 8628) con un solo client.
 * A database solo gli hash sha256 dei codici e dei token.
 */
class ConnettoriAi
{
    public const CLIENT_ID = 'cartellaviva-mac';
    public const PERMESSO = 'connettore_ai:manage';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConnettoreAiRepository $connettori,
        private readonly ConnettoreAiRichiestaRepository $richieste,
        private readonly LavoroAiRepository $lavori,
        private readonly PermissionResolver $permessi,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Passo 1 (dal Mac): nuova richiesta di abbinamento.
     *
     * @return array{device_code: string, user_code: string, expires_in: int, interval: int}
     */
    public function richiedi(string $nome): array
    {
        $ora = new \DateTimeImmutable();
        do {
            $codice = ConnettoreAiRichiesta::nuovoCodice();
        } while ($this->richieste->codiceInUso($codice, $ora));
        $device = TokenCasuale::nuovo();
        $this->em->persist(new ConnettoreAiRichiesta(self::hash($device), $codice, self::nome($nome)));
        $this->richieste->pulisci($ora);
        $this->em->flush();

        return ['device_code' => $device, 'user_code' => $codice, 'expires_in' => ConnettoreAiRichiesta::DURATA_SECONDI, 'interval' => ConnettoreAiRichiesta::INTERVALLO_SECONDI];
    }

    /** La richiesta viva con quel codice (per mostrarla prima di approvare), o 404. */
    public function richiesta(string $userCode): ConnettoreAiRichiesta
    {
        $codice = ConnettoreAiRichiesta::normalizzaCodice($userCode);

        return (null === $codice ? null : $this->richieste->inAttesa($codice, new \DateTimeImmutable()))
            ?? throw new NonTrovato('Codice non valido o scaduto: sul Mac ne compare uno nuovo.');
    }

    /** Passo 2 (dall'app): l'admin approva. Con la 2FA attiva serve anche il codice. */
    public function approva(Tenant $tenant, User $chi, string $userCode, ?string $nome, ?string $codice2fa): void
    {
        $r = $this->richiesta($userCode);
        if ($chi->isTwoFactorEnabled()) {
            if (null === $codice2fa || '' === trim($codice2fa)) {
                throw new NonConsentito('Serve il codice della verifica in due passaggi.');
            }
            $this->twoFactor->assertSecondFactor($chi, trim($codice2fa), 'connettore_ai.approva');
        }
        $r->approva($tenant, $chi, null === $nome ? null : self::nome($nome));
        $this->em->flush();
        $this->audit->log('connettore_ai.approvato', AuditOutcome::SUCCESS, resourceType: 'connettore_ai', tenantId: $tenant->getId(), context: ['nome' => $r->getNomeProposto()]);
    }

    public function nega(Tenant $tenant, string $userCode): void
    {
        $r = $this->richiesta($userCode);
        $r->nega();
        $this->em->flush();
        $this->audit->log('connettore_ai.negato', AuditOutcome::SUCCESS, resourceType: 'connettore_ai', tenantId: $tenant->getId());
    }

    /**
     * Passo 3 (dal Mac): scambia il device_code con i token. Errori come da
     * RFC 8628: authorization_pending, slow_down, access_denied, expired_token.
     *
     * @return array<string, mixed>
     */
    public function scambia(string $deviceCode): array
    {
        $r = $this->richieste->perDeviceCode(self::hash($deviceCode)) ?? throw new ErroreOAuth('invalid_grant', 'Codice del dispositivo sconosciuto.');
        $ora = new \DateTimeImmutable();
        if (ConnettoreAiRichiesta::USATA === $r->getStato()) {
            throw new ErroreOAuth('invalid_grant', 'Codice già usato.');
        }
        if ($r->isScaduta($ora)) {
            throw new ErroreOAuth('expired_token', 'La richiesta è scaduta: ricomincia dal Mac.');
        }
        if (ConnettoreAiRichiesta::NEGATA === $r->getStato()) {
            throw new ErroreOAuth('access_denied', 'Collegamento rifiutato.');
        }
        $presto = $r->troppoPresto($ora);
        if (ConnettoreAiRichiesta::IN_ATTESA === $r->getStato()) {
            $this->em->flush();
            throw new ErroreOAuth($presto ? 'slow_down' : 'authorization_pending', $presto ? 'Interroga più piano.' : 'In attesa dell\'approvazione nell\'app.');
        }
        $tenant = $r->getTenant();
        $chi = $r->getApprovataDa();
        if (null === $tenant || null === $chi) {
            throw new ErroreOAuth('access_denied', 'Collegamento rifiutato.');
        }
        $access = TokenCasuale::nuovo();
        $refresh = TokenCasuale::nuovo();
        $c = new ConnettoreAi($tenant, $r->getNomeProposto(), $chi, self::hash($access), self::hash($refresh));
        $r->usata();
        $this->em->persist($c);
        $this->em->flush();
        $this->audit->log('connettore_ai.collegato', AuditOutcome::SUCCESS, resourceType: 'connettore_ai', resourceId: (string) $c->getId(), tenantId: $tenant->getId(), context: ['nome' => $c->getNome()], actor: $chi);

        return self::token($access, $refresh);
    }

    /**
     * Rinnovo con il refresh token, a uso singolo: uno già usato revoca il
     * connettore (qualcuno potrebbe averlo copiato).
     *
     * @return array<string, mixed>
     */
    public function rinnova(string $refreshToken): array
    {
        $hash = self::hash($refreshToken);
        $c = $this->connettori->perRefreshToken($hash);
        if (null === $c) {
            $copiato = $this->connettori->perRefreshPrecedente($hash);
            if (null !== $copiato) {
                $this->revoca($copiato, 'refresh_riusato');
            }
            throw new ErroreOAuth('invalid_grant', 'Refresh token non valido.');
        }
        if (!$c->rinnovoValido(new \DateTimeImmutable()) || !$this->approvazioneValida($c)) {
            throw new ErroreOAuth('invalid_grant', 'Collegamento scaduto o revocato: ricollega il Mac.');
        }
        $access = TokenCasuale::nuovo();
        $refresh = TokenCasuale::nuovo();
        $c->ruotaToken(self::hash($access), self::hash($refresh));
        $this->em->flush();

        return self::token($access, $refresh);
    }

    /** Chi ha approvato è ancora nella famiglia con il permesso? */
    public function approvazioneValida(ConnettoreAi $c): bool
    {
        $chi = $c->getApprovatoDa();

        return null !== $chi && \in_array(self::PERMESSO, $this->permessi->getPermissionCodes($chi, $c->getTenant()), true);
    }

    public function contatto(ConnettoreAi $c): void
    {
        $c->contatto();
        $this->em->flush();
    }

    /** Scollega: token non più validi, lavori assegnati rimessi in attesa. */
    public function revoca(ConnettoreAi $c, string $motivo = 'utente'): void
    {
        if ($c->isRevocato()) {
            return;
        }
        $c->revoca();
        foreach ($this->lavori->delConnettore($c) as $l) {
            $l->rimetti(false);
        }
        $this->em->flush();
        $this->audit->log('connettore_ai.scollegato', AuditOutcome::SUCCESS, resourceType: 'connettore_ai', resourceId: (string) $c->getId(), tenantId: $c->getTenant()->getId(), context: ['motivo' => $motivo]);
    }

    public function online(Tenant $tenant): ?ConnettoreAi
    {
        return $this->connettori->online($tenant, new \DateTimeImmutable());
    }

    public static function nome(string $nome): string
    {
        $n = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nome));
        if ('' === $n) {
            throw new DatiNonValidi('Dai un nome al Mac.');
        }

        return mb_substr($n, 0, 80);
    }

    /** @return array<string, mixed> */
    private static function token(string $access, string $refresh): array
    {
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => ConnettoreAi::ACCESSO_SECONDI, 'refresh_token' => $refresh, 'scope' => 'ai-connector'];
    }
}
