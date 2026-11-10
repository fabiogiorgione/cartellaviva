<?php

namespace App\Import\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Import\ApiResource\ImportSourceResource;
use App\Import\Enum\ImportProvider;
use App\Import\Enum\ImportStrategia;
use App\Import\ImportSourceService;
use App\Import\Repository\ImportSourceRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<ImportSourceResource, ImportSourceResource|null>
 */
final class ImportSourceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly ImportSourceRepository $sources,
        private readonly ImportSourceService $service,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ImportSourceResource
    {
        $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], ImportSourceService::PERMISSION);

        if ($operation instanceof DeleteOperationInterface) {
            $source = $this->sources->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Sorgente non trovata.');
            $this->service->delete($source);

            return null;
        }

        \assert($data instanceof ImportSourceResource);
        $source = $this->service->create($tenant, $this->access->currentUser(), ImportProvider::from($data->provider), $data->cartellaRadiceId, ImportStrategia::from($data->strategia));

        return ImportSourceResource::fromEntity($source);
    }
}
