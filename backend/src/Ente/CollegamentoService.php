<?php

namespace App\Ente;

use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\User;
use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\Collegamento;
use App\Ente\Entity\EnteProfilo;
use App\Ente\Entity\Referente;
use App\Ente\Enum\ReferenteStato;
use App\Shared\Audit\AuditLogger;
use App\Shared\Doctrine\PerId;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonPiuValido;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Collegamento ente <-> famiglia.
 *
 * - L'ente invita un referente (solo dopo conferma dell'operatore, solo se
 *   verificato). L'email parte sempre: l'ente non scopre se esiste un account.
 * - Il referente apre il link: accetta scegliendo famiglia e persona (che
 *   l'ente non vedrà mai), rifiuta, oppure dice "non sono io".
 * - La famiglia può interrompere il collegamento quando vuole; le copie già
 *   consegnate restano all'ente.
 */
final class CollegamentoService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantAccess $access,
        private readonly Posta $posta,
        private readonly AuditLogger $audit,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * @param iterable<Referente> $referenti
     *
     * @return array{inviati: int, saltati: int}
     */
    public function invita(Tenant $ente, iterable $referenti, User $actor): array
    {
        $inviati = 0;
        $saltati = 0;
        foreach ($referenti as $referente) {
            if (!$referente->isInvitabile()) {
                ++$saltati;
                continue;
            }
            $token = TokenCasuale::nuovo();
            $referente->invita(self::hashToken($token));
            $this->em->flush();
            $this->posta->invia((string) $referente->getEmail(), sprintf('%s ti invita a collegarti su CartellaViva', $ente->getName()), 'emails/collegamento_invito.html.twig', [
                    'ente' => $ente->getName(),
                    'nome' => $referente->getNome(),
                    'iscritto' => $referente->getIscritto()->getNome(),
                    'url' => rtrim($this->frontendUrl, '/').'/collegamenti/invito?token='.$token,
                ]);
            $this->audit->log('collegamento.invitato', AuditOutcome::SUCCESS, resourceType: 'ente_referente', resourceId: (string) $referente->getId(), tenantId: $ente->getId(), actor: $actor);
            ++$inviati;
        }

        return ['inviati' => $inviati, 'saltati' => $saltati];
    }

    /** Referente con invito valido, oppure 410. */
    public function daToken(string $token): Referente
    {
        $referente = '' !== $token ? $this->em->getRepository(Referente::class)->findOneBy(['tokenHash' => self::hashToken($token)]) : null;
        if (!$referente instanceof Referente || !$referente->isInvitoValido()) {
            throw new NonPiuValido('Invito non valido, scaduto o già usato: chiedi all\'ente di inviarne un altro.');
        }

        return $referente;
    }

    /** @return array<string, mixed> cosa vede chi apre il link (dati che l'email già conteneva) */
    public function anteprima(Referente $referente): array
    {
        $ente = $referente->getTenant();
        $profilo = $this->em->getRepository(EnteProfilo::class)->findOneBy(['tenant' => $ente]);
        $anno = $this->em->getRepository(AnnoEnte::class)->corrente($ente);
        $gruppi = [];
        foreach ($referente->getIscritto()->getGruppi() as $g) {
            if (null !== $anno && $g->getAnno()->getId()->equals($anno->getId())) {
                $gruppi[] = $g->getNome();
            }
        }

        return [
            'ente' => ['nome' => $ente->getName(), 'tipo' => $profilo?->getTipo()->value, 'verificato' => $profilo?->isVerificato() ?? false],
            'iscritto' => ['nome' => $referente->getIscritto()->getNome(), 'cognome' => $referente->getIscritto()->getCognome()],
            'gruppi' => $gruppi,
            'anno' => $anno?->getNome(),
            'referente' => ['nome' => $referente->getNome(), 'cognome' => $referente->getCognome(), 'email' => $referente->getEmail()],
        ];
    }

    /**
     * Accetta: l'utente deve essere quello dell'email invitata e poter gestire i
     * collegamenti nella famiglia scelta. La persona è una esistente della
     * famiglia o una nuova (solo nome e cognome).
     *
     * @param array{nome?: string, cognome?: string}|null $nuovaPersona
     */
    public function accetta(Referente $referente, User $user, Tenant $famiglia, ?string $personaId, ?array $nuovaPersona): Collegamento
    {
        if (TenantType::PRIVATO !== $famiglia->getType()) {
            throw new DatiNonValidi('tenantId: scegli una famiglia.');
        }
        $this->access->require('collegamento:manage', $famiglia);
        if (User::normalizeEmail((string) $referente->getEmail()) !== $user->getEmail()) {
            $this->audit->log('collegamento.accettato', AuditOutcome::DENIED, resourceType: 'ente_referente', resourceId: (string) $referente->getId(), tenantId: $referente->getTenant()->getId(), context: ['reason' => 'email_mismatch'], actor: $user);
            throw new Conflitto('L\'invito è intestato a un\'altra email: accedi con l\'account di quella casella, oppure chiedi all\'ente di correggere l\'indirizzo.');
        }
        // Il link (o il codice) è arrivato a questa casella: l'indirizzo è dimostrato.
        $user->segnaEmailVerificata();

        $persona = null;
        if (null !== $personaId) {
            $persona = PerId::trova($this->em, MembroFamiglia::class, $personaId);
            if (!$persona instanceof MembroFamiglia || !$persona->getTenant()->getId()->equals($famiglia->getId())) {
                throw new DatiNonValidi('personaId: persona non trovata nella famiglia.');
            }
        } elseif (null !== $nuovaPersona) {
            $this->access->require('membro_famiglia:manage', $famiglia);
            $nome = trim((string) ($nuovaPersona['nome'] ?? ''));
            $cognome = trim((string) ($nuovaPersona['cognome'] ?? ''));
            if ('' === $nome || '' === $cognome) {
                throw new DatiNonValidi('nuovaPersona: nome e cognome obbligatori.');
            }
            $anagrafica = new Anagrafica(Anagrafica::TYPEPRIVATO);
            $anagrafica->setNome(mb_substr($nome, 0, 100));
            $anagrafica->setCognome(mb_substr($cognome, 0, 100));
            $persona = new MembroFamiglia($famiglia, $user, $anagrafica);
            $this->em->persist($persona);
        }

        $collegamento = $this->em->getRepository(Collegamento::class)->findOneBy(['referente' => $referente]);
        if ($collegamento instanceof Collegamento) {
            $collegamento->riattiva($famiglia, $persona, $user);
        } else {
            $collegamento = new Collegamento($famiglia, $referente, $persona, $user);
            $this->em->persist($collegamento);
        }
        $referente->concludiInvito(ReferenteStato::COLLEGATO);
        $this->em->flush();
        $this->logEntrambi('collegamento.accettato', $collegamento, $user);

        return $collegamento;
    }

    public function rifiuta(Referente $referente, bool $nonSonoIo, ?User $user): void
    {
        $referente->concludiInvito($nonSonoIo ? ReferenteStato::NON_SONO_IO : ReferenteStato::RIFIUTATO);
        $this->em->flush();
        $this->audit->log($nonSonoIo ? 'collegamento.non_sono_io' : 'collegamento.rifiutato', AuditOutcome::SUCCESS, resourceType: 'ente_referente', resourceId: (string) $referente->getId(), tenantId: $referente->getTenant()->getId(), actor: $user);
    }

    public function revoca(Collegamento $collegamento, User $user): void
    {
        if (!$collegamento->isAttivo()) {
            throw new Conflitto('Il collegamento è già interrotto.');
        }
        $collegamento->revoca();
        $collegamento->getReferente()->concludiInvito(ReferenteStato::REVOCATO);
        $this->em->flush();
        $this->logEntrambi('collegamento.revocato', $collegamento, $user);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', 'collegamento|'.$token);
    }

    /** Audit su entrambi i lati, senza dati della famiglia nella riga dell'ente. */
    private function logEntrambi(string $action, Collegamento $c, User $user): void
    {
        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'ente_collegamento', resourceId: (string) $c->getId(), tenantId: $c->getEnte()->getId(), context: ['referente' => (string) $c->getReferente()->getId()], actor: $user);
        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'ente_collegamento', resourceId: (string) $c->getId(), tenantId: $c->getFamiglia()->getId(), context: ['ente' => $c->getEnte()->getName()], actor: $user);
    }
}
