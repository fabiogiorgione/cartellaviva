<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\ApiResource\MemberResource;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\MembershipManager;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Repository\RoleRepository;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<MemberResource, MemberResource|null>
 */
final class MemberProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly MembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly MembershipManager $manager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?MemberResource
    {
        $actor = $this->access->currentUser();
        $tenant = $this->tenants->findByPublicId((string) $uriVariables['tenantId']) ?? throw new NotFoundHttpException('Tenant non trovato.');
        $membership = $this->memberships->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Membro non trovato.');
        $isSelf = $membership->getUser()->getId()->equals($actor->getId());

        if ($operation instanceof DeleteOperationInterface) {
            if (!$isSelf) {
                $this->access->require('membro:remove', $membership);
            }
            $this->manager->remove($actor, $membership);

            return null;
        }

        \assert($data instanceof MemberResource);
        $previous = $context['previous_data'] ?? null;
        \assert($previous instanceof MemberResource);

        if (null !== $data->roleId && $data->roleId !== $previous->roleId) {
            $this->access->require('membro:manage_role', $membership);
            $role = $this->roles->findAssignableById($tenant, $data->roleId)
                ?? throw new UnprocessableEntityHttpException('Ruolo non valido per questo tenant.');
            $this->manager->changeRole($actor, $membership, $role);
        }

        if (null !== $data->status && $data->status !== $previous->status) {
            $this->access->require('membro:remove', $membership);
            if ($isSelf && MembershipStatus::ACTIVE !== $data->status) {
                throw new UnprocessableEntityHttpException('Non puoi sospendere il tuo stesso accesso: usa "esci dal tenant".');
            }
            $this->manager->changeStatus($actor, $membership, $data->status);
        }

        return MemberResource::fromEntity($membership, $actor);
    }
}
