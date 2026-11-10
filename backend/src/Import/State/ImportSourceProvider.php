<?php

namespace App\Import\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Import\ApiResource\ImportSourceResource;
use App\Import\Entity\ImportSource;
use App\Import\ImportSourceService;
use App\Import\Repository\ImportSourceRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<ImportSourceResource>
 */
final class ImportSourceProvider implements ProviderInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly ImportSourceRepository $sources,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], ImportSourceService::PERMISSION);

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(static fn (ImportSource $s) => ImportSourceResource::fromEntity($s), $this->sources->findByTenant($tenant));
        }

        $source = $this->sources->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Sorgente non trovata.');

        return ImportSourceResource::fromEntity($source);
    }
}
