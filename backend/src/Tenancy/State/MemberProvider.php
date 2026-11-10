<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\ApiResource\MemberResource;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<MemberResource>
 */
final class MemberProvider implements ProviderInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly MembershipRepository $memberships,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $user = $this->access->currentUser();

        if ($operation instanceof CollectionOperationInterface) {
            $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], 'membro:read');

            return array_map(
                static fn ($m) => MemberResource::fromEntity($m, $user),
                $this->memberships->findForTenant($tenant),
            );
        }

        $tenant = $this->tenants->findByPublicId((string) $uriVariables['tenantId']) ?? throw new NotFoundHttpException('Tenant non trovato.');
        $membership = $this->memberships->findOneInTenant($tenant, (string) $uriVariables['id']);

        // Chi esce da solo dal tenant non ha bisogno di membro:read.
        $leavingSelf = $operation instanceof DeleteOperationInterface && null !== $membership && $membership->getUser()->getId()->equals($user->getId());
        if (!$leavingSelf) {
            // Il permesso si verifica sul tenant PRIMA di rivelare se il membro esiste.
            $this->access->require('membro:read', $tenant);
        }
        if (null === $membership) {
            throw new NotFoundHttpException('Membro non trovato.');
        }

        return MemberResource::fromEntity($membership, $user);
    }
}
