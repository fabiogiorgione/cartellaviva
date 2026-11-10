<?php

namespace App\Shared\Entity;

use App\Shared\Enum\AuditOutcome;
use App\Shared\Repository\AuditLogRepository;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Registro append-only: chi, cosa, su quale risorsa, quando, con quale esito.
 *
 * Attore e tenant sono salvati come UUID senza foreign key, così il registro
 * sopravvive alla cancellazione di utenti e tenant. La scrittura avviene via
 * DBAL (App\Shared\Audit\AuditLogger), indipendente dalla unit of work dell'ORM.
 * Un trigger PostgreSQL (vedi migration) impedisce UPDATE e DELETE.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_tenant_time', columns: ['tenant_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_actor_time', columns: ['actor_id', 'occurred_at'])]
#[PermissionResource('audit', 'Audit log', [
    'read' => 'Consulta il registro accessi del tenant',
])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $actorId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actorEmail = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $tenantId = null;

    /** Es. "auth.login", "permission.check", "membership.role_changed". */
    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $permission = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $resourceType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\Column(length: 20, enumType: AuditOutcome::class)]
    private AuditOutcome $outcome;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $context = [];

    private function __construct()
    {
    }

    public function getId(): ?string
    {
        return null === $this->id ? null : (string) $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorId(): ?Uuid
    {
        return $this->actorId;
    }

    public function getActorEmail(): ?string
    {
        return $this->actorEmail;
    }

    public function getTenantId(): ?Uuid
    {
        return $this->tenantId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getPermission(): ?string
    {
        return $this->permission;
    }

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function getOutcome(): AuditOutcome
    {
        return $this->outcome;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }
}
