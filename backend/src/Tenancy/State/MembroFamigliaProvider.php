<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\ApiResource\MembroFamigliaResource;
use App\Tenancy\Repository\MembroFamigliaRepository;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<MembroFamigliaResource>
 */
final class MembroFamigliaProvider implements ProviderInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly MembroFamigliaRepository $membri,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], 'membro_famiglia:read');

            return array_map(MembroFamigliaResource::fromEntity(...), $this->membri->findForTenant($tenant));
        }

        $tenant = $this->tenants->findByPublicId((string) $uriVariables['tenantId']) ?? throw new NotFoundHttpException('Tenant non trovato.');
        // Permesso verificato sul tenant prima di rivelare se il membro esiste.
        $this->access->require('membro_famiglia:read', $tenant);
        $membro = $this->membri->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Membro della famiglia non trovato.');

        return MembroFamigliaResource::fromEntity($membro);
    }
}
