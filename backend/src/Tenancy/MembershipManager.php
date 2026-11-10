<?php

namespace App\Tenancy;

use App\Account\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\ErroreDominio;
use App\Shared\Errore\NonConsentito;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantPermissionVoter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regole di dominio sulle Membership. Il permesso base (membro:manage_role,
 * membro:remove) lo verifica il chiamante tramite voter; qui si applicano le
 * regole che dipendono dallo stato:
 * - il tenant deve avere sempre almeno un owner attivo (niente rimozione,
 *   declassamento o sospensione dell'ultimo);
 * - la membership di un owner la modifica solo un owner (o il superadmin);
 * - anti-escalation sul ruolo assegnato (RoleGrantPolicy).
 * Le violazioni vengono registrate nell'audit log con esito "denied".
 */
final class MembershipManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipRepository $memberships,
        private readonly RoleGrantPolicy $grantPolicy,
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $resolver,
        private readonly TenantPermissionVoter $voter,
        private readonly OwnershipTransfer $ownership,
    ) {
    }

    public function changeRole(User $actor, Membership $membership, Role $newRole): void
    {
        $oldRole = $membership->getRole();
        if ($oldRole->getId()->equals($newRole->getId())) {
            return;
        }

        $this->guarded($membership, 'membership.role_changed', ['from' => $oldRole->getSlug(), 'to' => $newRole->getSlug()], function () use ($actor, $membership, $newRole, $oldRole): void {
            $this->grantPolicy->assertCanGrant($actor, $membership->getTenant(), $newRole);
            if ($oldRole->isOwnerRole()) {
                $this->assertActorMayTouchOwner($actor, $membership);
                if (!$newRole->isOwnerRole()) {
                    $this->assertNotLastOwner($membership);
                }
            }
            $membership->changeRole($newRole);
        });
    }

    public function changeStatus(User $actor, Membership $membership, MembershipStatus $status): void
    {
        if ($membership->getStatus() === $status) {
            return;
        }

        $this->guarded($membership, 'membership.status_changed', ['to' => $status->value], function () use ($actor, $membership, $status): void {
            if ($membership->getRole()->isOwnerRole()) {
                $this->assertActorMayTouchOwner($actor, $membership);
                if (MembershipStatus::ACTIVE !== $status) {
                    $this->assertNotLastOwner($membership);
                }
            }
            $membership->setStatus($status);
        });
    }

    public function remove(User $actor, Membership $membership): void
    {
        $self = $membership->getUser()->getId()->equals($actor->getId());

        $transferred = [];
        $successor = null;
        $this->guarded($membership, $self ? 'membership.left' : 'membership.removed', ['user' => $membership->getUser()->getEmail()], function () use ($actor, $membership, &$transferred, &$successor): void {
            if ($membership->getRole()->isOwnerRole()) {
                $this->assertActorMayTouchOwner($actor, $membership);
                $this->assertNotLastOwner($membership);
            }
            // I dati di chi esce passano a un proprietario, nella stessa transazione.
            $successor = $this->ownership->successorFor($membership->getTenant(), $membership->getUser(), $actor);
            if (null !== $successor) {
                $transferred = $this->ownership->transferAll($membership->getTenant(), $membership->getUser(), $successor);
            }
            $this->em->remove($membership);
        });

        if ([] !== $transferred && null !== $successor) {
            $this->audit->log('membership.ownership_transferred', AuditOutcome::SUCCESS, resourceType: 'membership', tenantId: $membership->getTenant()->getId(),
                context: ['from' => $membership->getUser()->getEmail(), 'to' => $successor->getEmail(), 'risorse' => $transferred]);
        }
    }

    /**
     * Esegue la modifica in transazione (il lock sugli owner vale fino al commit)
     * e registra l'esito, anche quando una regola la blocca.
     *
     * @param array<string, mixed> $context
     */
    private function guarded(Membership $membership, string $action, array $context, callable $change): void
    {
        $tenantId = $membership->getTenant()->getId();
        $membershipId = (string) $membership->getId();

        // Transazione manuale (non wrapInTransaction, che chiude l'EntityManager
        // su eccezione): le regole si verificano prima di qualsiasi modifica.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $change();
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            if ($e instanceof ErroreDominio) {
                $this->audit->log($action, AuditOutcome::DENIED, resourceType: 'membership', resourceId: $membershipId, tenantId: $tenantId, context: $context + ['reason' => $e->getMessage()]);
            }
            throw $e;
        }

        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'membership', resourceId: $membershipId, tenantId: $tenantId, context: $context);
        // I permessi appena cambiati devono valere già nel resto della richiesta.
        $this->resolver->reset();
        $this->voter->reset();
    }

    private function assertActorMayTouchOwner(User $actor, Membership $target): void
    {
        $self = $target->getUser()->getId()->equals($actor->getId());
        if (!$self && !$actor->isSuperAdmin() && !$this->grantPolicy->isOwner($actor, $target->getTenant())) {
            throw new NonConsentito('Solo un proprietario può modificare l\'accesso di un altro proprietario.');
        }
    }

    private function assertNotLastOwner(Membership $target): void
    {
        if (!$target->isActive()) {
            return; // un owner già sospeso non conta tra gli owner attivi
        }
        if ($this->memberships->countActiveOwnersForUpdate($target->getTenant()) <= 1) {
            throw new Conflitto('Il tenant deve avere almeno un proprietario attivo: nomina prima un altro proprietario.');
        }
    }
}
