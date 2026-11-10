<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\ApiResource\InvitationResource;
use App\Tenancy\InvitationService;
use App\Tenancy\Repository\InvitationRepository;
use App\Tenancy\Repository\RoleRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<InvitationResource, InvitationResource|null>
 */
final class InvitationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly InvitationRepository $invitations,
        private readonly RoleRepository $roles,
        private readonly InvitationService $service,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?InvitationResource
    {
        $actor = $this->access->currentUser();
        $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], 'membro:invite');

        if ($operation instanceof DeleteOperationInterface) {
            $invitation = $this->invitations->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Invito non trovato.');
            $this->service->revoke($invitation);

            return null;
        }

        \assert($data instanceof InvitationResource);
        $role = $this->roles->findAssignableById($tenant, $data->roleId)
            ?? throw new UnprocessableEntityHttpException('Ruolo non valido per questo tenant.');

        return InvitationResource::fromEntity($this->service->invite($actor, $tenant, $data->email, $role));
    }
}
