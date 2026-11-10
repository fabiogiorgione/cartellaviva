<?php

namespace App\Tenancy\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Tenancy\Entity\Role;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\State\RoleProcessor;
use App\Tenancy\State\RoleProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * GET    /api/tenants/{tenantId}/roles        ruoli usabili nel tenant (membro:read)
 * POST   /api/tenants/{tenantId}/roles        crea un ruolo personalizzato (ruolo:manage)
 * PATCH  /api/tenants/{tenantId}/roles/{id}   solo ruoli personalizzati (ruolo:manage)
 * DELETE /api/tenants/{tenantId}/roles/{id}   solo se non assegnato (ruolo:manage)
 */
#[ApiResource(
    shortName: 'Role',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/roles', uriVariables: ['tenantId'], provider: RoleProvider::class),
        new Get(uriTemplate: '/tenants/{tenantId}/roles/{id}', uriVariables: ['tenantId', 'id'], provider: RoleProvider::class),
        new Post(uriTemplate: '/tenants/{tenantId}/roles', uriVariables: ['tenantId'], read: false, processor: RoleProcessor::class),
        new Patch(uriTemplate: '/tenants/{tenantId}/roles/{id}', uriVariables: ['tenantId', 'id'], provider: RoleProvider::class, processor: RoleProcessor::class),
        new Delete(uriTemplate: '/tenants/{tenantId}/roles/{id}', uriVariables: ['tenantId', 'id'], provider: RoleProvider::class, processor: RoleProcessor::class),
    ],
    normalizationContext: ['groups' => ['role:read']],
    denormalizationContext: ['groups' => ['role:write']],
)]
final class RoleResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['role:read'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['role:read'])]
    public ?string $tenantId = null;

    #[Groups(['role:read'])]
    public ?string $slug = null;

    #[Groups(['role:read', 'role:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Groups(['role:read', 'role:write'])]
    #[Assert\Length(max: 255)]
    public ?string $description = null;

    #[Groups(['role:read'])]
    public bool $system = false;

    #[Groups(['role:read'])]
    public ?TenantType $tenantType = null;

    /** @var list<string> codici effettivi (diretti + gruppi) */
    #[Groups(['role:read', 'role:write'])]
    #[Assert\All([new Assert\Type('string')])]
    public array $permissions = [];

    public static function fromEntity(Role $role, string $tenantId): self
    {
        $resource = new self();
        $resource->id = (string) $role->getId();
        $resource->tenantId = $tenantId;
        $resource->slug = $role->getSlug();
        $resource->name = $role->getName();
        $resource->description = $role->getDescription();
        $resource->system = $role->isSystem();
        $resource->tenantType = $role->getTenantType();
        $resource->permissions = $role->getEffectivePermissionCodes();

        return $resource;
    }
}
