<?php

namespace App\Tenancy\Entity;

use App\Tenancy\Repository\PermissionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Voce del catalogo permessi. Non si crea a mano:
 * la popola app:permissions:sync a partire da #[PermissionResource] sulle
 * entità e da config/packages/permissions.yaml.
 */
#[ORM\Entity(repositoryClass: PermissionRepository::class)]
#[ORM\Table(name: 'permission')]
#[ORM\UniqueConstraint(name: 'uniq_permission_code', columns: ['code'])]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Es. "documento:write:own". */
    #[ORM\Column(length: 100)]
    private string $code;

    #[ORM\Column(length: 50)]
    private string $resource;

    #[ORM\Column(length: 50)]
    private string $action;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(options: ['default' => true])]
    private bool $sensitive = true;

    /** True se la voce non compare più nel catalogo (non viene cancellata in automatico). */
    #[ORM\Column(options: ['default' => false])]
    private bool $obsolete = false;

    public function __construct(string $code)
    {
        $this->code = $code;
        [$this->resource, $this->action] = explode(':', $code, 2);
        $this->label = $code;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive;
    }

    public function isObsolete(): bool
    {
        return $this->obsolete;
    }

    public function syncFromCatalog(string $label, bool $sensitive): void
    {
        $this->label = $label;
        $this->sensitive = $sensitive;
        $this->obsolete = false;
    }

    public function markObsolete(): void
    {
        $this->obsolete = true;
    }
}
