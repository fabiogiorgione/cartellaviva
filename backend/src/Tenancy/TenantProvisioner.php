<?php

namespace App\Tenancy;

use App\Account\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crea un tenant insieme alla Membership "owner" del suo creatore:
 * un tenant non esiste mai senza almeno un proprietario.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleRepository $roles,
        private readonly AuditLogger $audit,
    ) {
    }

    public function createWithOwner(User $owner, string $name, TenantType $type): Membership
    {
        $tenant = new Tenant($name, $type);
        $membership = new Membership($owner, $tenant, $this->roles->getSystemRole(Role::OWNER));

        $this->em->persist($tenant);
        $this->em->persist($membership);
        $this->em->flush();

        $this->audit->log('tenant.created', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), context: ['type' => $type->value], actor: $owner);

        return $membership;
    }
}
