<?php

namespace App\Shared\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Shared\Entity\AuditLog;
use App\Shared\State\AuditLogProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * GET /api/tenants/{tenantId}/audit_logs   registro del tenant (audit:read)
 * GET /api/admin/audit_logs                registro completo (solo superadmin)
 * Paginazione a cursore: ?before=<id>&limit=<1..200>
 */
#[ApiResource(
    shortName: 'AuditLog',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/audit_logs', uriVariables: ['tenantId'], provider: AuditLogProvider::class, paginationEnabled: false),
        new GetCollection(uriTemplate: '/admin/audit_logs', provider: AuditLogProvider::class, paginationEnabled: false),
    ],
    normalizationContext: ['groups' => ['audit:read']],
)]
final class AuditLogResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['audit:read'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['audit:read'])]
    public ?string $tenantId = null;

    #[Groups(['audit:read'])]
    public ?\DateTimeImmutable $occurredAt = null;

    #[Groups(['audit:read'])]
    public ?string $actorEmail = null;

    #[Groups(['audit:read'])]
    public ?string $action = null;

    #[Groups(['audit:read'])]
    public ?string $permission = null;

    #[Groups(['audit:read'])]
    public ?string $resourceType = null;

    #[Groups(['audit:read'])]
    public ?string $resourceId = null;

    #[Groups(['audit:read'])]
    public ?string $outcome = null;

    #[Groups(['audit:read'])]
    public ?string $ip = null;

    /** @var array<string, mixed> */
    #[Groups(['audit:read'])]
    public array $context = [];

    public static function fromEntity(AuditLog $log): self
    {
        $resource = new self();
        $resource->id = $log->getId();
        $resource->tenantId = $log->getTenantId()?->toRfc4122();
        $resource->occurredAt = $log->getOccurredAt();
        $resource->actorEmail = $log->getActorEmail();
        $resource->action = $log->getAction();
        $resource->permission = $log->getPermission();
        $resource->resourceType = $log->getResourceType();
        $resource->resourceId = $log->getResourceId();
        $resource->outcome = $log->getOutcome()->value;
        $resource->ip = $log->getIp();
        $resource->context = $log->getContext();

        return $resource;
    }
}
