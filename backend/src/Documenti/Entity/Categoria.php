<?php

namespace App\Documenti\Entity;

use App\Documenti\Repository\CategoriaRepository;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Categoria dei documenti.
 * - tenant = null: categoria di sistema, uguale per tutti i tenant, definita in
 *   config/packages/categorie.yaml (app:categorie:sync), non modificabile via API;
 * - tenant valorizzato: categoria personalizzata del tenant.
 *
 * isSensibile = true forza la cifratura dei documenti associati (e del loro testo OCR).
 */
#[ORM\Entity(repositoryClass: CategoriaRepository::class)]
#[ORM\Table(name: 'categoria')]
#[ORM\UniqueConstraint(name: 'uniq_categoria_system_slug', columns: ['slug'], options: ['where' => '(tenant_id IS NULL)'])]
#[ORM\HasLifecycleCallbacks]
#[PermissionResource('categoria', 'Categorie', [
    'manage' => 'Crea, modifica ed elimina le categorie personalizzate',
])]
class Categoria
{
    use TimestampableTrait;

    /** Categoria di sistema dei documenti importati in attesa di classificazione (sensibile). */
    public const SLUG_DA_CATEGORIZZARE = 'da_categorizzare';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant;

    /** Solo per le categorie di sistema (chiave stabile per la sincronizzazione). */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $nome;

    /** Nome di un'icona Lucide (es. "file-heart"), interpretata dal frontend. */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9_-]{1,50}$/', message: 'Icona non valida')]
    private string $icona;

    #[ORM\Column(length: 7)]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Colore non valido (formato #RRGGBB)')]
    private string $colore;

    #[ORM\Column(options: ['default' => false])]
    private bool $isSensibile;

    public function __construct(?Tenant $tenant, string $nome, string $icona, string $colore, bool $isSensibile, ?string $slug = null)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->nome = $nome;
        $this->icona = $icona;
        $this->colore = $colore;
        $this->isSensibile = $isSensibile;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function isSystem(): bool
    {
        return null === $this->tenant;
    }

    /** Utilizzabile nel tenant: di sistema oppure sua. */
    public function isUsableIn(Tenant $tenant): bool
    {
        return null === $this->tenant || $this->tenant->getId()->equals($tenant->getId());
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function isDaCategorizzare(): bool
    {
        return $this->isSystem() && self::SLUG_DA_CATEGORIZZARE === $this->slug;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getIcona(): string
    {
        return $this->icona;
    }

    public function getColore(): string
    {
        return $this->colore;
    }

    public function isSensibile(): bool
    {
        return $this->isSensibile;
    }

    public function update(string $nome, string $icona, string $colore, bool $isSensibile): void
    {
        $this->nome = $nome;
        $this->icona = $icona;
        $this->colore = strtolower($colore);
        $this->isSensibile = $isSensibile;
    }
}
