<?php

namespace App\Shared\Entity\Contract;

use App\Tenancy\Entity\Tenant;

/**
 * Risorsa che appartiene a un tenant. Il TenantPermissionVoter ricava il tenant
 * da qui: senza Membership attiva su questo tenant l'accesso è sempre negato.
 */
interface TenantScopedInterface
{
    public function getTenant(): Tenant;
}
