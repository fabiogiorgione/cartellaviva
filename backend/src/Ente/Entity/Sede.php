<?php

namespace App\Ente\Entity;

use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Sede o plesso dell'ente (i gruppi possono indicarne una). */
#[ORM\Entity]
#[ORM\Table(name: 'ente_sede')]
class Sede implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 120)]
    private string $nome;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $indirizzo = null;

    public function __construct(Tenant $tenant, string $nome, ?string $indirizzo = null)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->nome = $nome;
        $this->indirizzo = $indirizzo;
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

    public function getIndirizzo(): ?string
    {
        return $this->indirizzo;
    }

    public function aggiorna(string $nome, ?string $indirizzo): void
    {
        $this->nome = $nome;
        $this->indirizzo = $indirizzo;
    }
}
