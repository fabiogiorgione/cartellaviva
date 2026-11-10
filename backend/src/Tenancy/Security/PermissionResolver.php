<?php

namespace App\Tenancy\Security;

use App\Account\Entity\User;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Repository\MembershipRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Risolve i permessi di un utente IN UN TENANT, leggendoli dal DB a ogni
 * richiesta (il JWT porta solo l'identità): un cambio di ruolo o una rimozione
 * hanno effetto immediato, senza attendere la scadenza del token.
 *
 * La cache è solo per la durata della richiesta (ResetInterface: viene svuotata
 * anche in worker mode FrankenPHP).
 */
class PermissionResolver implements ResetInterface
{
    /** @var array<string, array{membership: ?Membership, codes: list<string>}> */
    private array $cache = [];

    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    public function getActiveMembership(User $user, Tenant $tenant): ?Membership
    {
        return $this->resolve($user, $tenant)['membership'];
    }

    /**
     * Codici effettivi (con eventuale scope :own / :all). Vuoto se non esiste una
     * Membership attiva: in quel caso nessun permesso, indipendentemente da ruoli altrove.
     *
     * @return list<string>
     */
    public function getPermissionCodes(User $user, Tenant $tenant): array
    {
        return $this->resolve($user, $tenant)['codes'];
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /** @return array{membership: ?Membership, codes: list<string>} */
    private function resolve(User $user, Tenant $tenant): array
    {
        $key = $user->getId()->toRfc4122().'|'.$tenant->getId()->toRfc4122();

        if (!isset($this->cache[$key])) {
            $membership = $user->isActive() ? $this->memberships->findActiveWithPermissions($user, $tenant) : null;
            $this->cache[$key] = [
                'membership' => $membership,
                'codes' => $membership?->getRole()->getEffectivePermissionCodes() ?? [],
            ];
        }

        return $this->cache[$key];
    }
}
