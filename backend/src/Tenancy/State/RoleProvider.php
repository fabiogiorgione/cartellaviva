<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\ApiResource\RoleResource;
use App\Tenancy\Repository\RoleRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<RoleResource>
 */
final class RoleProvider implements ProviderInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly RoleRepository $roles,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $tenantId = (string) $uriVariables['tenantId'];
        $tenant = $this->access->requireTenant($tenantId, 'membro:read');

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(static fn ($r) => RoleResource::fromEntity($r, $tenantId), $this->roles->findAssignableIn($tenant));
        }

        $role = $this->roles->findAssignableById($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Ruolo non trovato.');

        return RoleResource::fromEntity($role, $tenantId);
    }
}
