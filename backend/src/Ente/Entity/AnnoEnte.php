<?php

namespace App\Ente\Entity;

use App\Ente\Repository\AnnoEnteRepository;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Anno scolastico o sociale ("2026/27"). Uno solo è in corso; i precedenti sono
 * archiviati in sola lettura (richieste e firme restano consultabili).
 */
#[ORM\Entity(repositoryClass: AnnoEnteRepository::class)]
#[ORM\Table(name: 'ente_anno')]
#[ORM\UniqueConstraint(name: 'uniq_ente_anno_nome', columns: ['tenant_id', 'nome'])]
class AnnoEnte implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 20)]
    private string $nome;

    #[ORM\Column]
    private bool $archiviato = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, string $nome)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->nome = $nome;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function isArchiviato(): bool
    {
        return $this->archiviato;
    }

    public function archivia(): void
    {
        $this->archiviato = true;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
