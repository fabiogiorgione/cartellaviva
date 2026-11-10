<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\ApiResource\InvitationResource;
use App\Tenancy\Repository\InvitationRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<InvitationResource>
 */
final class InvitationProvider implements ProviderInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly InvitationRepository $invitations,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], 'membro:invite');

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(InvitationResource::fromEntity(...), $this->invitations->findForTenant($tenant));
        }

        $invitation = $this->invitations->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Invito non trovato.');

        return InvitationResource::fromEntity($invitation);
    }
}
