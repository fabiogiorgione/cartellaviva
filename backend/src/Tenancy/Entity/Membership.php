<?php

namespace App\Tenancy\Entity;

use App\Account\Entity\User;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\Permission\Attribute\PermissionResource;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Chi (User) ha quale ruolo (Role) in quale tenant (Tenant).
 * È l'unica fonte dei permessi applicativi: niente Membership attiva, niente accesso.
 */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\Table(name: 'membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_tenant', columns: ['user_id', 'tenant_id'])]
#[ORM\Index(name: 'idx_membership_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
#[PermissionResource('membro', 'Membri', [
    'read' => 'Visualizza i membri del tenant',
    'invite' => 'Invita nuovi membri',
    'manage_role' => 'Assegna o cambia il ruolo dei membri',
    'remove' => 'Rimuove o sospende l\'accesso dei membri',
])]
class Membership implements TenantScopedInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Role $role;

    #[ORM\Column(length: 20, enumType: MembershipStatus::class)]
    private MembershipStatus $status = MembershipStatus::ACTIVE;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy;

    /**
     * Solo per l'accesso d'emergenza: id delle categorie di documenti visibili
     * (null = tutte). Il voter nega i documenti di altre categorie.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $categorieConsentite = null;

    public function __construct(User $user, Tenant $tenant, Role $role, ?User $invitedBy = null)
    {
        if (!$role->isAssignableIn($tenant)) {
            throw new \DomainException('Il ruolo non è assegnabile in questo tenant.');
        }
        $this->init($user, $tenant, $role, $invitedBy);
    }

    /**
     * Accesso d'emergenza concesso (ruolo "emergenza", non assegnabile a mano).
     *
     * @param list<string>|null $categorieConsentite
     */
    public static function perEmergenza(User $user, Tenant $tenant, Role $role, ?array $categorieConsentite): self
    {
        if (Role::EMERGENZA !== $role->getSlug()) {
            throw new \DomainException('Ruolo d\'emergenza non valido.');
        }
        $m = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $m->init($user, $tenant, $role, null);
        $m->categorieConsentite = null === $categorieConsentite ? null : array_values($categorieConsentite);

        return $m;
    }

    private function init(User $user, Tenant $tenant, Role $role, ?User $invitedBy): void
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tenant = $tenant;
        $this->role = $role;
        $this->status = MembershipStatus::ACTIVE;
        $this->invitedBy = $invitedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** @return list<string>|null */
    public function getCategorieConsentite(): ?array
    {
        return $this->categorieConsentite;
    }

    /** Categoria eliminata: non è più tra quelle visibili (un elenco vuoto resta "nessuna", mai "tutte"). */
    public function togliCategoriaConsentita(string $categoriaId): void
    {
        if (null !== $this->categorieConsentite) {
            $this->categorieConsentite = array_values(array_diff($this->categorieConsentite, [$categoriaId]));
        }
    }

    public function consenteCategoria(Uuid $categoriaId): bool
    {
        return null === $this->categorieConsentite || \in_array($categoriaId->toRfc4122(), $this->categorieConsentite, true);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function changeRole(Role $role): void
    {
        if (!$role->isAssignableIn($this->tenant)) {
            throw new \DomainException('Il ruolo non è assegnabile in questo tenant.');
        }
        $this->role = $role;
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function setStatus(MembershipStatus $status): void
    {
        $this->status = $status;
    }

    public function isActive(): bool
    {
        return MembershipStatus::ACTIVE === $this->status && $this->user->isActive();
    }

    /** Proprietario che oggi può agire: membership attiva, utente non bloccato. */
    public function isProprietarioAttivo(): bool
    {
        return $this->isActive() && $this->role->isOwnerRole();
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }
}
