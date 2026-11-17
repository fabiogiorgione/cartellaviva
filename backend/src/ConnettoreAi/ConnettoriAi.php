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
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\NonTrovato;
use App\Shared\Errore\TroppiTentativi;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Posta $posta,
        #[Autowire(service: 'limiter.connettore_password')]
        private readonly RateLimiterFactoryInterface $limitePassword,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
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

    /**
     * Passo 2 (dall'app): l'admin approva, sempre con una conferma. Il Mac
     * riceverà il testo dei documenti: un tocco da solo non basta.
     * - 2FA attiva: il codice;
     * - altrimenti la password;
     * - né 2FA né password (accesso solo con Google): 409, prima si attiva una delle due.
     */
    public function approva(Tenant $tenant, User $chi, string $userCode, ?string $nome, ?string $codice2fa, ?string $password = null): void
    {
        $r = $this->richiesta($userCode);
        if ($chi->isTwoFactorEnabled()) {
            if (null === $codice2fa || '' === trim($codice2fa)) {
                throw new NonConsentito('Serve il codice della verifica in due passaggi.');
            }
            $this->twoFactor->assertSecondFactor($chi, trim($codice2fa), 'connettore_ai.approvato');
        } elseif (!$chi->hasPassword()) {
            throw new Conflitto('Per collegare un Mac attiva la verifica in due passaggi o imposta una password (Sicurezza e accesso).');
        } else {
            $this->confermaPassword($chi, $password);
        }
        $r->approva($tenant, $chi, null === $nome ? null : self::nome($nome));
        $this->em->flush();
        $this->audit->log('connettore_ai.approvato', AuditOutcome::SUCCESS, resourceType: 'connettore_ai', tenantId: $tenant->getId(), context: ['nome' => $r->getNomeProposto()]);
    }

    /** La password di chi approva, con un limite di tentativi sbagliati per utente. */
    private function confermaPassword(User $chi, ?string $password): void
    {
        if (null === $password || '' === $password) {
            throw new NonConsentito('Serve la password del tuo account.');
        }
        $limite = $this->limitePassword->create($chi->getId()->toRfc4122());
        if (0 === $limite->consume(0)->getRemainingTokens()) {
            throw new TroppiTentativi('Troppe password sbagliate: riprova tra un quarto d\'ora.');
        }
        if (!$this->hasher->isPasswordValid($chi, $password)) {
            $limite->consume();
            $this->audit->log('connettore_ai.approvato', AuditOutcome::FAILURE, resourceType: 'user', resourceId: (string) $chi->getId(), context: ['reason' => 'wrong_password'], actor: $chi);
            throw new DatiNonValidi('La password non è corretta.');
        }
        $limite->reset();
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
     * La richiesta si legge con un blocco in scrittura: due scambi insieme
     * dello stesso codice creano un solo connettore.
     *
     * @return array<string, mixed>
     */
    public function scambia(string $deviceCode): array
    {
        [$c, $access, $refresh] = $this->inTransazione(function () use ($deviceCode): array|ErroreOAuth {
            $r = $this->richieste->perDeviceCodeBloccata(self::hash($deviceCode)) ?? throw new ErroreOAuth('invalid_grant', 'Codice del dispositivo sconosciuto.');
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
                // Il momento dell'interrogazione resta salvato anche con l'errore (slow_down).
                $this->em->flush();

                return new ErroreOAuth($presto ? 'slow_down' : 'authorization_pending', $presto ? 'Interroga più piano.' : 'In attesa dell\'approvazione nell\'app.');
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

            return [$c, $access, $refresh];
        });
        $chi = $c->getApprovatoDa();
        $this->audit->log('connettore_ai.collegato', AuditOutcome::SUCCESS, resourceType: 'connettore_ai', resourceId: (string) $c->getId(), tenantId: $c->getTenant()->getId(), context: ['nome' => $c->getNome()], actor: $chi);
        if (null !== $chi) {
            $this->avvisoCollegato($chi, $c);
        }

        return self::token($access, $refresh);
    }

    /**
     * Rinnovo con il refresh token, a uso singolo. Il token di prima vale
     * ancora per TOLLERANZA_SECONDI dalla rotazione (la risposta può essersi
     * persa): dopo, uno già usato revoca il connettore (qualcuno potrebbe
     * averlo copiato). Un rinnovo alla volta per connettore.
     *
     * @return array<string, mixed>
     */
    public function rinnova(string $refreshToken): array
    {
        return $this->inTransazione(function () use ($refreshToken): array|ErroreOAuth {
            $hash = self::hash($refreshToken);
            $ora = new \DateTimeImmutable();
            $c = $this->connettori->perRefreshBloccato($hash);
            if (null === $c) {
                return new ErroreOAuth('invalid_grant', 'Refresh token non valido.');
            }
            $precedente = $hash !== $c->getRefreshTokenHash();
            if ($precedente && !$c->inTolleranza($ora)) {
                $this->revoca($c, 'refresh_riusato');

                return new ErroreOAuth('invalid_grant', 'Refresh token non valido.');
            }
            if (!$c->rinnovoValido($ora) || !$this->approvazioneValida($c)) {
                return new ErroreOAuth('invalid_grant', 'Collegamento scaduto o revocato: ricollega il Mac.');
            }
            $access = TokenCasuale::nuovo();
            $refresh = TokenCasuale::nuovo();
            if ($precedente) {
                $c->ruotaInTolleranza(self::hash($access), self::hash($refresh));
            } else {
                $c->ruotaToken(self::hash($access), self::hash($refresh));
            }
            $this->em->flush();

            return self::token($access, $refresh, $c->accessoSecondi($ora));
        });
    }

    /**
     * Esegue in una transazione (i blocchi in scrittura valgono fino al commit).
     * Un ErroreOAuth restituito si lancia dopo il commit, così quello che è
     * stato scritto prima (revoca, momento dell'interrogazione) resta; uno
     * lanciato annulla tutto.
     *
     * @template T
     *
     * @param callable(): (T|ErroreOAuth) $lavoro
     *
     * @return T
     */
    private function inTransazione(callable $lavoro): mixed
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $esito = $lavoro();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
        if ($esito instanceof ErroreOAuth) {
            throw $esito;
        }

        return $esito;
    }

    /** A chi ha approvato: un Mac è stato collegato. Solo nome, data e dove scollegarlo. */
    private function avvisoCollegato(User $chi, ConnettoreAi $c): void
    {
        $this->posta->invia($chi->getEmail(), 'Un Mac è stato collegato alla tua famiglia', 'emails/connettore_collegato.html.twig', [
            'nome' => $chi->getFirstName(),
            'mac' => $c->getNome(),
            'quando' => $c->getApprovatoIl()->setTimezone(new \DateTimeZone('Europe/Rome'))->format('d/m/Y \a\l\l\e H:i'),
            'url' => rtrim($this->frontendUrl, '/').'/',
        ]);
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
    private static function token(string $access, string $refresh, int $secondi = ConnettoreAi::ACCESSO_SECONDI): array
    {
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $secondi, 'refresh_token' => $refresh, 'scope' => 'ai-connector'];
    }
}
