<?php

namespace App\Tenancy\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Account\Entity\User;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\State\MemberProcessor;
use App\Tenancy\State\MemberProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * GET    /api/tenants/{tenantId}/members        membro:read
 * GET    /api/tenants/{tenantId}/members/{id}   membro:read
 * PATCH  /api/tenants/{tenantId}/members/{id}   roleId -> membro:manage_role, status -> membro:remove
 * DELETE /api/tenants/{tenantId}/members/{id}   membro:remove (uscire da soli non lo richiede)
 */
#[ApiResource(
    shortName: 'Member',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/members', uriVariables: ['tenantId'], provider: MemberProvider::class),
        new Get(uriTemplate: '/tenants/{tenantId}/members/{id}', uriVariables: ['tenantId', 'id'], provider: MemberProvider::class),
        new Patch(uriTemplate: '/tenants/{tenantId}/members/{id}', uriVariables: ['tenantId', 'id'], provider: MemberProvider::class, processor: MemberProcessor::class),
        new Delete(uriTemplate: '/tenants/{tenantId}/members/{id}', uriVariables: ['tenantId', 'id'], provider: MemberProvider::class, processor: MemberProcessor::class),
    ],
    normalizationContext: ['groups' => ['member:read']],
    denormalizationContext: ['groups' => ['member:write']],
)]
final class MemberResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['member:read'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['member:read'])]
    public ?string $tenantId = null;

    #[Groups(['member:read'])]
    public ?string $userId = null;

    #[Groups(['member:read'])]
    public ?string $email = null;

    #[Groups(['member:read'])]
    public ?string $firstName = null;

    #[Groups(['member:read'])]
    public ?string $lastName = null;

    #[Groups(['member:read', 'member:write'])]
    public ?string $roleId = null;

    #[Groups(['member:read'])]
    public ?string $roleSlug = null;

    #[Groups(['member:read'])]
    public ?string $roleName = null;

    #[Groups(['member:read', 'member:write'])]
    public ?MembershipStatus $status = null;

    #[Groups(['member:read'])]
    public bool $isCurrentUser = false;

    #[Groups(['member:read'])]
    public ?\DateTimeImmutable $joinedAt = null;

    public static function fromEntity(Membership $membership, ?User $currentUser): self
    {
        $user = $membership->getUser();
        $role = $membership->getRole();

        $resource = new self();
        $resource->id = (string) $membership->getId();
        $resource->tenantId = (string) $membership->getTenant()->getId();
        $resource->userId = (string) $user->getId();
        $resource->email = $user->getEmail();
        $resource->firstName = $user->getFirstName();
        $resource->lastName = $user->getLastName();
        $resource->roleId = (string) $role->getId();
        $resource->roleSlug = $role->getSlug();
        $resource->roleName = $role->getName();
        $resource->status = $membership->getStatus();
        $resource->isCurrentUser = null !== $currentUser && $user->getId()->equals($currentUser->getId());
        $resource->joinedAt = $membership->getCreatedAt();

        return $resource;
    }
}
