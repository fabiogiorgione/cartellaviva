<?php

namespace App\Tenancy\Entity;

use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Permission\Attribute\PermissionResource;
use App\Tenancy\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ruolo assegnabile tramite Membership.
 *
 * - tenant = null  -> ruolo di sistema (owner/member/viewer/staff), definito in
 *   config/packages/rbac.yaml, sincronizzato da app:roles:sync, non modificabile via API.
 * - tenant != null -> ruolo personalizzato creato da quel tenant.
 * - tenantType     -> tipo di tenant a cui il ruolo è applicabile (null = tutti).
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'role')]
#[ORM\UniqueConstraint(name: 'uniq_role_tenant_slug', columns: ['tenant_id', 'slug'])]
// In PostgreSQL i NULL sono distinti: serve un indice parziale per gli slug di sistema.
#[ORM\UniqueConstraint(name: 'uniq_role_system_slug', columns: ['slug'], options: ['where' => '(tenant_id IS NULL)'])]
#[ORM\HasLifecycleCallbacks]
#[PermissionResource('ruolo', 'Ruoli', [
    'manage' => 'Crea e modifica i ruoli personalizzati del tenant',
])]
class Role
{
    use TimestampableTrait;

    public const OWNER = 'owner';
    public const MEMBER = 'member';
    public const VIEWER = 'viewer';
    public const STAFF = 'staff';
    /** Accesso d'emergenza concesso dopo l'attesa: solo lettura, mai assegnabile a mano. */
    public const EMERGENZA = 'emergenza';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant;

    #[ORM\Column(length: 20, nullable: true, enumType: TenantType::class)]
    private ?TenantType $tenantType;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'role_permission')]
    private Collection $permissions;

    /** @var Collection<int, PermissionGroup> */
    #[ORM\ManyToMany(targetEntity: PermissionGroup::class)]
    #[ORM\JoinTable(name: 'role_permission_group')]
    private Collection $permissionGroups;

    public function __construct(string $slug, string $name, ?Tenant $tenant = null, ?TenantType $tenantType = null)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->tenant = $tenant;
        $this->tenantType = $tenant?->getType() ?? $tenantType;
        $this->permissions = new ArrayCollection();
        $this->permissionGroups = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function describe(string $name, ?string $description): void
    {
        $this->name = $name;
        $this->description = $description;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getTenantType(): ?TenantType
    {
        return $this->tenantType;
    }

    public function setTenantType(?TenantType $tenantType): void
    {
        $this->tenantType = $tenantType;
    }

    public function isSystem(): bool
    {
        return null === $this->tenant;
    }

    public function isOwnerRole(): bool
    {
        return $this->isSystem() && self::OWNER === $this->slug;
    }

    /**
     * Un ruolo è usabile in un tenant se è suo (e il tenant è in modalità avanzata),
     * oppure di sistema e applicabile al suo tipo.
     */
    public function isAssignableIn(Tenant $tenant): bool
    {
        // Nasce solo dalla procedura d'emergenza (attesa senza opposizione).
        if (null === $this->tenant && self::EMERGENZA === $this->slug) {
            return false;
        }
        if (null !== $this->tenant) {
            return $this->tenant->getId()->equals($tenant->getId()) && $tenant->isModalitaAvanzata();
        }

        return null === $this->tenantType || $this->tenantType === $tenant->getType();
    }

    /** @return Collection<int, Permission> */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    /** @param iterable<Permission> $permissions */
    public function replacePermissions(iterable $permissions): void
    {
        $this->permissions->clear();
        foreach ($permissions as $permission) {
            if (!$this->permissions->contains($permission)) {
                $this->permissions->add($permission);
            }
        }
    }

    /** @return Collection<int, PermissionGroup> */
    public function getPermissionGroups(): Collection
    {
        return $this->permissionGroups;
    }

    /** @param iterable<PermissionGroup> $groups */
    public function replacePermissionGroups(iterable $groups): void
    {
        $this->permissionGroups->clear();
        foreach ($groups as $group) {
            if (!$this->permissionGroups->contains($group)) {
                $this->permissionGroups->add($group);
            }
        }
    }

    /**
     * Permessi effettivi: diretti + quelli dei PermissionGroup (
     * ma calcolato per ruolo e non appiattito sull'utente).
     *
     * @return list<string>
     */
    public function getEffectivePermissionCodes(): array
    {
        $codes = [];
        foreach ($this->permissions as $permission) {
            if (!$permission->isObsolete()) {
                $codes[$permission->getCode()] = true;
            }
        }
        foreach ($this->permissionGroups as $group) {
            foreach ($group->getPermissions() as $permission) {
                if (!$permission->isObsolete()) {
                    $codes[$permission->getCode()] = true;
                }
            }
        }
        $codes = array_keys($codes);
        sort($codes);

        return $codes;
    }
}
