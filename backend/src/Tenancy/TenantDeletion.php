<?php

namespace App\Tenancy;

use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\User;
use App\Account\TwoFactor\TwoFactorService;
use App\Documenti\DocumentStorage;
use App\Documenti\Entity\Documento;
use App\Import\Entity\ImportSource;
use App\Import\ImportSourceService;
use App\Scadenze\Entity\Scadenza;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Shared\Mail\Posta;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cancellazione di una famiglia, in due tempi:
 *
 * 1. richiesta (solo un proprietario, con il nome della famiglia e il codice 2FA):
 *    il tenant diventa di sola lettura per N giorni, Google Drive viene scollegato
 *    (token revocati) e tutti i membri ricevono un avviso;
 * 2. allo scadere (job orario) si cancellano file, chiavi dei documenti e dati.
 *    Restano solo le righe dell'audit log (senza vincoli verso il tenant).
 *
 * Qualsiasi proprietario può annullare entro la scadenza.
 */
final class TenantDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipRepository $memberships,
        private readonly TwoFactorService $twoFactor,
        private readonly ImportSourceService $imports,
        private readonly DocumentStorage $storage,
        private readonly Posta $posta,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.tenant_deletion_grace_days%')]
        private readonly int $graceDays,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function request(Tenant $tenant, User $user, string $conferma, string $codice): void
    {
        $this->assertOwner($tenant, $user);
        if (TenantType::PRIVATO !== $tenant->getType()) {
            throw new Conflitto('Per ora si possono eliminare solo le famiglie.');
        }
        if ($tenant->isInEliminazione()) {
            throw new Conflitto('L\'eliminazione di questa famiglia è già programmata.');
        }
        if (0 !== strcmp(mb_strtolower(trim($conferma)), mb_strtolower($tenant->getName()))) {
            throw new DatiNonValidi('conferma: scrivi esattamente il nome della famiglia.');
        }
        if (!$user->isTwoFactorEnabled()) {
            throw new Conflitto('Per eliminare la famiglia attiva prima la verifica in due passaggi nel tuo account.');
        }
        $this->twoFactor->assertSecondFactor($user, $codice, 'tenant.deletion_requested');

        $this->programma($tenant, $user);
        $this->audit->log('tenant.deletion_requested', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), context: ['programmata_il' => $tenant->getEliminazioneProgrammataIl()?->format(\DATE_ATOM)], actor: $user);
        $this->notify($tenant, 'emails/famiglia_eliminazione.html.twig', sprintf('La famiglia "%s" sarà eliminata', $tenant->getName()), $user->getFullName());
    }

    public function cancel(Tenant $tenant, User $user): void
    {
        $this->assertOwner($tenant, $user);
        if (!$tenant->isInEliminazione()) {
            throw new Conflitto('Non c\'è nessuna eliminazione programmata.');
        }
        if ($tenant->isEliminazioneDalPortale()) {
            throw new Conflitto('L\'eliminazione è stata decisa dal team di CartellaViva: per annullarla scrivi al supporto.');
        }
        $tenant->cancelDeletion();
        $this->em->flush();
        $this->audit->log('tenant.deletion_cancelled', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), actor: $user);
        $this->notify($tenant, 'emails/famiglia_eliminazione_annullata.html.twig', sprintf('La famiglia "%s" non sarà più eliminata', $tenant->getName()), $user->getFullName());
    }

    /**
     * Dal portale (superadmin): famiglie ed enti, senza 2FA del proprietario
     * ma con un motivo, che resta nel registro. Stessa attesa; la annulla solo il portale.
     */
    public function richiediDalPortale(Tenant $tenant, User $admin, string $motivo): void
    {
        if ($tenant->isInEliminazione()) {
            throw new Conflitto('L\'eliminazione è già programmata.');
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            throw new DatiNonValidi('motivo: spiega perché si elimina (resta nel registro).');
        }
        $this->programma($tenant, $admin, true);
        $this->audit->log('portale.tenant_eliminazione_programmata', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(),
            context: ['motivo' => mb_substr($motivo, 0, 500), 'programmata_il' => $tenant->getEliminazioneProgrammataIl()?->format(\DATE_ATOM)], actor: $admin);
        $this->notify($tenant, 'emails/famiglia_eliminazione.html.twig', sprintf('"%s" sarà eliminato da CartellaViva', $tenant->getName()), 'Il team di CartellaViva');
    }

    public function annullaDalPortale(Tenant $tenant, User $admin): void
    {
        if (!$tenant->isInEliminazione()) {
            throw new Conflitto('Non c\'è nessuna eliminazione programmata.');
        }
        $tenant->cancelDeletion();
        $this->em->flush();
        $this->audit->log('portale.tenant_eliminazione_annullata', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), actor: $admin);
        $this->notify($tenant, 'emails/famiglia_eliminazione_annullata.html.twig', sprintf('"%s" non sarà più eliminato', $tenant->getName()), 'Il team di CartellaViva');
    }

    /** Sola lettura per N giorni, link esterni revocati, Google Drive scollegato. */
    private function programma(Tenant $tenant, User $by, bool $dalPortale = false): void
    {
        $tenant->requestDeletion($by, new \DateTimeImmutable(sprintf('+%d days', $this->graceDays)), $dalPortale);
        // Nessun documento esce più verso l'esterno durante l'attesa.
        $this->em->createQuery('UPDATE '.\App\Documenti\Entity\CondivisioneEsterna::class.' c SET c.revocataIl = :ora, c.sessioneHash = NULL WHERE c.tenant = :t AND c.revocataIl IS NULL')
            ->setParameter('ora', new \DateTimeImmutable())->setParameter('t', $tenant->getId(), 'uuid')->execute();
        $this->em->flush();
        $this->imports->disconnectAll($tenant, 'Eliminazione programmata: Google Drive è stato scollegato.');
    }

    /** Elimina definitivamente i tenant arrivati alla scadenza. */
    public function purgeDue(): int
    {
        $due = $this->em->createQuery('SELECT t FROM '.Tenant::class.' t WHERE t.eliminazioneProgrammataIl IS NOT NULL AND t.eliminazioneProgrammataIl <= :now ORDER BY t.eliminazioneProgrammataIl')
            ->setParameter('now', new \DateTimeImmutable())
            ->getResult();
        $eliminati = 0;
        foreach ($due as $tenant) {
            try {
                $this->purge($tenant);
                ++$eliminati;
            } catch (\Throwable $e) {
                $this->logger->error('Eliminazione del tenant {id} fallita', ['id' => (string) $tenant->getId(), 'exception' => $e]);
                // wrapInTransaction chiude l'EntityManager: senza riaprirlo fallirebbero
                // anche le eliminazioni successive e il resto della pulizia periodica.
                if (!$this->em->isOpen()) {
                    $this->doctrine->resetManager();
                }
            }
        }

        return $eliminati;
    }

    private function purge(Tenant $tenant): void
    {
        $id = $tenant->getId();
        $documenti = (int) $this->em->createQuery('SELECT COUNT(d.id) FROM '.Documento::class.' d WHERE d.tenant = :t')->setParameter('t', $id, 'uuid')->getSingleScalarResult();
        $anagrafiche = array_column($this->em->createQuery('SELECT IDENTITY(m.anagrafica) AS a FROM '.MembroFamiglia::class.' m WHERE m.tenant = :t')->setParameter('t', $id, 'uuid')->getScalarResult(), 'a');

        $this->em->wrapInTransaction(function () use ($tenant, $id, $anagrafiche): void {
            // Prima le tabelle con FK RESTRICT verso il tenant; il resto va in CASCADE.
            foreach ([\App\Documenti\Entity\CondivisioneEsterna::class, \App\Pratiche\Entity\Pagamento::class, \App\Pratiche\Entity\Pratica::class, Scadenza::class, Documento::class, ImportSource::class, MembroFamiglia::class] as $class) {
                $this->em->createQuery('DELETE FROM '.$class.' x WHERE x.tenant = :t')->setParameter('t', $id, 'uuid')->execute();
            }
            if ([] !== $anagrafiche) {
                $this->em->createQuery('DELETE FROM '.Anagrafica::class.' a WHERE a.id IN (:ids)')->setParameter('ids', $anagrafiche)->execute();
            }
            $this->em->createQuery('DELETE FROM '.Tenant::class.' t WHERE t.id = :t')->setParameter('t', $id, 'uuid')->execute();
        });
        $this->em->clear();

        // I file solo dopo il commit: se il DB fallisce i documenti restano leggibili.
        $this->storage->deleteTenant($id);
        $this->audit->log('tenant.purged', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $id, tenantId: $id, context: ['documenti' => $documenti]);
    }

    private function assertOwner(Tenant $tenant, User $user): void
    {
        $membership = $this->memberships->findOneByUserAndTenant($user, $tenant);
        if (null === $membership || MembershipStatus::ACTIVE !== $membership->getStatus() || !$membership->getRole()->isOwnerRole()) {
            $this->audit->log('tenant.deletion_denied', AuditOutcome::DENIED, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), actor: $user);
            throw new NonConsentito('Solo un proprietario può eliminare la famiglia o annullarne l\'eliminazione.');
        }
    }

    /** Avviso a tutti i membri attivi (nessun dato dei documenti nell'email). */
    private function notify(Tenant $tenant, string $template, string $subject, string $da): void
    {
        $ente = TenantType::AZIENDA === $tenant->getType();
        foreach ($this->memberships->findForTenant($tenant) as $membership) {
            if (MembershipStatus::ACTIVE !== $membership->getStatus() || $membership->getUser()->isEliminato()) {
                continue;
            }
            $this->posta->invia($membership->getUser()->getEmail(), $subject, $template, [
                    'nome' => $membership->getUser()->getFirstName(),
                    'famiglia' => $tenant->getName(),
                    'cosa' => $ente ? 'l\'ente' : 'la famiglia',
                    'ente' => $ente,
                    'da' => $da,
                    'data' => $tenant->getEliminazioneProgrammataIl(),
                    'url' => rtrim($this->frontendUrl, '/').($ente ? '/ente' : '/famiglia/impostazioni'),
                ]);
        }
    }
}
