<?php

namespace App\Tenancy\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Tenancy\Entity\Invitation;
use App\Tenancy\State\InvitationProcessor;
use App\Tenancy\State\InvitationProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * GET    /api/tenants/{tenantId}/invitations        membro:invite
 * POST   /api/tenants/{tenantId}/invitations        membro:invite (+ regole anti-escalation sul ruolo)
 * DELETE /api/tenants/{tenantId}/invitations/{id}   revoca, membro:invite
 * Anteprima e accettazione (pubbliche, con token) sono in InvitationController.
 */
#[ApiResource(
    shortName: 'Invitation',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/invitations', uriVariables: ['tenantId'], provider: InvitationProvider::class),
        new Get(uriTemplate: '/tenants/{tenantId}/invitations/{id}', uriVariables: ['tenantId', 'id'], provider: InvitationProvider::class),
        new Post(uriTemplate: '/tenants/{tenantId}/invitations', uriVariables: ['tenantId'], read: false, processor: InvitationProcessor::class),
        new Delete(uriTemplate: '/tenants/{tenantId}/invitations/{id}', uriVariables: ['tenantId', 'id'], provider: InvitationProvider::class, processor: InvitationProcessor::class),
    ],
    normalizationContext: ['groups' => ['invitation:read']],
    denormalizationContext: ['groups' => ['invitation:write']],
)]
final class InvitationResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['invitation:read'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['invitation:read'])]
    public ?string $tenantId = null;

    #[Groups(['invitation:read', 'invitation:write'])]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Groups(['invitation:read', 'invitation:write'])]
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $roleId = '';

    #[Groups(['invitation:read'])]
    public ?string $roleName = null;

    #[Groups(['invitation:read'])]
    public ?string $status = null;

    #[Groups(['invitation:read'])]
    public ?string $invitedBy = null;

    #[Groups(['invitation:read'])]
    public ?\DateTimeImmutable $expiresAt = null;

    #[Groups(['invitation:read'])]
    public ?\DateTimeImmutable $createdAt = null;

    public static function fromEntity(Invitation $invitation): self
    {
        $resource = new self();
        $resource->id = (string) $invitation->getId();
        $resource->tenantId = (string) $invitation->getTenant()->getId();
        $resource->email = $invitation->getEmail();
        $resource->roleId = (string) $invitation->getRole()->getId();
        $resource->roleName = $invitation->getRole()->getName();
        $resource->status = $invitation->getStatus();
        $resource->invitedBy = $invitation->getInvitedBy()?->getFullName();
        $resource->expiresAt = $invitation->getExpiresAt();
        $resource->createdAt = $invitation->getCreatedAt();

        return $resource;
    }
}
