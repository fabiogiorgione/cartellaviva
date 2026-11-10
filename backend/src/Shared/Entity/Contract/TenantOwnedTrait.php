<?php

namespace App\Shared\Entity\Contract;

use App\Account\Entity\User;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Colonne tenant_id + owner_user_id, entrambe NOT NULL.
 *
 * Per i dati dei figli minori il proprietario è il genitore che ha caricato il
 * record: in questa fase i minori non hanno un account.
 * Il TenantOwnershipGuard verifica inoltre, al primo salvataggio, che il
 * proprietario abbia una Membership attiva sul tenant.
 */
trait TenantOwnedTrait
{
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $owner;

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function transferOwnership(User $newOwner): void
    {
        $this->owner = $newOwner;
    }
}
