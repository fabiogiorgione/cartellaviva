<?php

namespace App\Ente\Entity;

use App\Ente\Repository\IscrittoRepository;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Iscritto dell'ente (alunno, socio, partecipante): anagrafica dell'ente, che
 * ne è titolare del trattamento. Spesso è un minore: la risorsa è "sensitive",
 * quindi ogni accesso concesso finisce nell'audit, e si conservano solo i dati
 * minimi (nome, cognome, data di nascita facoltativa).
 */
#[ORM\Entity(repositoryClass: IscrittoRepository::class)]
#[ORM\Table(name: 'ente_iscritto')]
#[ORM\Index(name: 'idx_ente_iscritto_nome', columns: ['tenant_id', 'cognome', 'nome'])]
#[PermissionResource('iscritto', 'Iscritti', [
    'read' => 'Vede gli iscritti e i referenti (dei propri gruppi)',
    'manage' => 'Inserisce, importa e modifica iscritti e referenti',
    'invite' => 'Invia ai referenti l\'invito a collegarsi',
], sensitive: true)]
class Iscritto implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 100)]
    private string $nome;

    #[ORM\Column(length: 100)]
    private string $cognome;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dataNascita = null;

    /** @var Collection<int, Gruppo> */
    #[ORM\ManyToMany(targetEntity: Gruppo::class)]
    #[ORM\JoinTable(name: 'ente_iscrizione')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $gruppi;

    /** @var Collection<int, Referente> */
    #[ORM\OneToMany(targetEntity: Referente::class, mappedBy: 'iscritto', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $referenti;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, string $nome, string $cognome, ?\DateTimeImmutable $dataNascita = null)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->nome = $nome;
        $this->cognome = $cognome;
        $this->dataNascita = $dataNascita;
        $this->gruppi = new ArrayCollection();
        $this->referenti = new ArrayCollection();
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

    public function getCognome(): string
    {
        return $this->cognome;
    }

    public function getNomeCompleto(): string
    {
        return trim($this->nome.' '.$this->cognome);
    }

    public function getDataNascita(): ?\DateTimeImmutable
    {
        return $this->dataNascita;
    }

    public function aggiorna(string $nome, string $cognome, ?\DateTimeImmutable $dataNascita): void
    {
        $this->nome = $nome;
        $this->cognome = $cognome;
        $this->dataNascita = $dataNascita;
    }

    /** @return Collection<int, Gruppo> */
    public function getGruppi(): Collection
    {
        return $this->gruppi;
    }

    public function iscriviA(Gruppo $gruppo): void
    {
        if (!$this->gruppi->contains($gruppo)) {
            $this->gruppi->add($gruppo);
        }
    }

    public function togliDa(Gruppo $gruppo): void
    {
        $this->gruppi->removeElement($gruppo);
    }

    /** @return Collection<int, Referente> */
    public function getReferenti(): Collection
    {
        return $this->referenti;
    }

    public function aggiungiReferente(Referente $referente): void
    {
        if (!$this->referenti->contains($referente)) {
            $this->referenti->add($referente);
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
