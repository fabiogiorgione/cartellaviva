<?php

namespace App\Tenancy\Entity;

use App\Account\Entity\User;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Invito a entrare in un tenant con un ruolo. Del token si salva solo l'hash
 * SHA-256: il valore in chiaro esiste solo nell'email inviata.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_invitation_tenant_email', columns: ['tenant_id', 'email'])]
class Invitation implements TenantScopedInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(Tenant $tenant, string $email, Role $role, string $tokenHash, \DateTimeImmutable $expiresAt, ?User $invitedBy)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->email = User::normalizeEmail($email);
        $this->role = $role;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->invitedBy = $invitedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getStatus(?\DateTimeImmutable $now = null): string
    {
        return match (true) {
            null !== $this->acceptedAt => self::STATUS_ACCEPTED,
            null !== $this->revokedAt => self::STATUS_REVOKED,
            $this->expiresAt <= ($now ?? new \DateTimeImmutable()) => self::STATUS_EXPIRED,
            default => self::STATUS_PENDING,
        };
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->getStatus();
    }

    public function accept(User $user): void
    {
        if (!$this->isPending()) {
            throw new \DomainException('Invito non più valido.');
        }
        $this->acceptedAt = new \DateTimeImmutable();
        $this->acceptedBy = $user;
    }

    public function revoke(): void
    {
        if ($this->isPending()) {
            $this->revokedAt = new \DateTimeImmutable();
        }
    }
}
