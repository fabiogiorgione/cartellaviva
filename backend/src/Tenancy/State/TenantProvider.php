<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Documenti\Repository\DocumentoRepository;
use App\Tenancy\ApiResource\TenantResource;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantAccess;

/**
 * @implements ProviderInterface<TenantResource>
 */
final class TenantProvider implements ProviderInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly MembershipRepository $memberships,
        private readonly PermissionResolver $resolver,
        private readonly DocumentoRepository $documenti,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $user = $this->access->currentUser();

        if ($operation instanceof CollectionOperationInterface) {
            // Scoping per query: si elencano solo i tenant con Membership attiva.
            $mine = [];
            foreach ($this->memberships->findActiveForUser($user) as $membership) {
                $mine[(string) $membership->getTenant()->getId()] = TenantResource::fromEntity($membership->getTenant(), $membership);
            }
            if ($user->isSuperAdmin()) {
                foreach ($this->tenants->findBy([], ['name' => 'ASC']) as $tenant) {
                    $mine[(string) $tenant->getId()] ??= TenantResource::fromEntity($tenant, null);
                }
            }

            return array_values($mine);
        }

        $tenant = $this->access->requireTenant((string) $uriVariables['id'], 'tenant:read');
        $resource = TenantResource::fromEntity($tenant, $this->resolver->getActiveMembership($user, $tenant));
        $resource->spazioUsatoBytes = $this->documenti->totalBytes($tenant);

        return $resource;
    }
}
