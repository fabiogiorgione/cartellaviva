<?php

namespace App\Tenancy\Entity;

use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Repository\PermissionGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Livello intermedio Role -> PermissionGroup -> Permission.
 * tenant = null: gruppo di sistema (definito in config/packages/rbac.yaml).
 */
#[ORM\Entity(repositoryClass: PermissionGroupRepository::class)]
#[ORM\Table(name: 'permission_group')]
#[ORM\UniqueConstraint(name: 'uniq_permission_group_tenant_slug', columns: ['tenant_id', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_permission_group_system_slug', columns: ['slug'], options: ['where' => '(tenant_id IS NULL)'])]
#[ORM\HasLifecycleCallbacks]
class PermissionGroup
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class)]
    #[ORM\JoinTable(name: 'permission_group_permission')]
    private Collection $permissions;

    public function __construct(string $slug, string $name, ?Tenant $tenant = null)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->tenant = $tenant;
        $this->permissions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
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

    public function isSystem(): bool
    {
        return null === $this->tenant;
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
}
