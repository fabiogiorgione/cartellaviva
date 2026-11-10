<?php

namespace App\Tenancy\Security;

use App\Account\Entity\User;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\NonTrovato;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Repository\TenantRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Helper per provider/processor: carica il tenant dall'URL e impone il permesso
 * passando SEMPRE dal voter (che registra l'esito nell'audit log).
 */
final class TenantAccess
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly Security $security,
    ) {
    }

    public function requireTenant(string $tenantId, string $permission): Tenant
    {
        $tenant = $this->tenants->findByPublicId($tenantId) ?? throw new NonTrovato('Tenant non trovato.');
        $this->require($permission, $tenant);

        return $tenant;
    }

    public function require(string $permission, object $subject): void
    {
        if (!$this->security->isGranted($permission, $subject)) {
            throw new NonConsentito(sprintf('Permesso "%s" mancante.', $permission));
        }
    }

    public function isGranted(string $permission, object $subject): bool
    {
        return $this->security->isGranted($permission, $subject);
    }

    public function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new NonConsentito('Autenticazione richiesta.');
        }

        return $user;
    }
}
