<?php

namespace App\Tenancy;

use App\Account\Entity\User;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\PermissionResolver;

/**
 * Regole anti-escalation per assegnare un ruolo (cambio ruolo o invito) e per
 * definire i permessi di un ruolo personalizzato:
 * - il ruolo deve essere utilizzabile nel tenant (suo o di sistema del tipo giusto);
 * - il ruolo "owner" lo concede solo un owner (o il superadmin);
 * - chi non è owner non può concedere permessi che non possiede.
 */
final class RoleGrantPolicy
{
    public function __construct(private readonly PermissionResolver $resolver)
    {
    }

    public function isOwner(User $user, Tenant $tenant): bool
    {
        return (bool) $this->resolver->getActiveMembership($user, $tenant)?->getRole()->isOwnerRole();
    }

    public function assertCanGrant(User $actor, Tenant $tenant, Role $role): void
    {
        if (!$role->isAssignableIn($tenant)) {
            throw new DatiNonValidi('Ruolo non utilizzabile in questo tenant.');
        }
        if ($actor->isSuperAdmin() || $this->isOwner($actor, $tenant)) {
            return;
        }
        if ($role->isOwnerRole()) {
            throw new NonConsentito('Solo un proprietario può assegnare il ruolo di proprietario.');
        }
        $this->assertCanGrantPermissions($actor, $tenant, $role->getEffectivePermissionCodes());
    }

    /** @param list<string> $codes */
    public function assertCanGrantPermissions(User $actor, Tenant $tenant, array $codes): void
    {
        if ($actor->isSuperAdmin() || $this->isOwner($actor, $tenant)) {
            return;
        }
        $missing = array_diff($codes, $this->resolver->getPermissionCodes($actor, $tenant));
        if ([] !== $missing) {
            throw new NonConsentito('Non puoi concedere permessi che non possiedi: '.implode(', ', $missing));
        }
    }
}
