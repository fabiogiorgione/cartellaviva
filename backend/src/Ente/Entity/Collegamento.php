<?php

namespace App\Ente\Entity;

use App\Account\Entity\User;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Collegamento tra un referente dell'ente e una famiglia.
 *
 * Il tenant "proprietario" (per il voter) è la FAMIGLIA: è lei che lo vede, lo
 * gestisce e lo può interrompere. La persona abbinata (MembroFamiglia) resta
 * un'informazione della famiglia: l'ente non la vede mai. Il collegamento non
 * dà accesso all'archivio: l'ente riceve solo ciò che la famiglia consegna.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ente_collegamento')]
#[ORM\UniqueConstraint(name: 'uniq_ente_collegamento_referente', columns: ['referente_id'])]
#[PermissionResource('collegamento', 'Enti collegati', [
    'read' => 'Vede gli enti collegati e le loro richieste',
    'manage' => 'Accetta e interrompe i collegamenti con gli enti',
], sensitive: false)]
class Collegamento implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** Famiglia. */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'famiglia_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $famiglia;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'ente_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $ente;

    #[ORM\OneToOne(targetEntity: Referente::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Referente $referente;

    #[ORM\ManyToOne(targetEntity: MembroFamiglia::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MembroFamiglia $persona;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $accettatoDa;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revocatoIl = null;

    public function __construct(Tenant $famiglia, Referente $referente, ?MembroFamiglia $persona, User $accettatoDa)
    {
        $this->id = Uuid::v7();
        $this->famiglia = $famiglia;
        $this->ente = $referente->getTenant();
        $this->referente = $referente;
        $this->persona = $persona;
        $this->accettatoDa = $accettatoDa;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->famiglia;
    }

    public function getFamiglia(): Tenant
    {
        return $this->famiglia;
    }

    public function getEnte(): Tenant
    {
        return $this->ente;
    }

    public function getReferente(): Referente
    {
        return $this->referente;
    }

    public function getPersona(): ?MembroFamiglia
    {
        return $this->persona;
    }

    public function getAccettatoDa(): ?User
    {
        return $this->accettatoDa;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAttivo(): bool
    {
        return null === $this->revocatoIl;
    }

    public function getRevocatoIl(): ?\DateTimeImmutable
    {
        return $this->revocatoIl;
    }

    public function revoca(): void
    {
        $this->revocatoIl ??= new \DateTimeImmutable();
    }

    /** Un nuovo invito accettato dopo una revoca riattiva il collegamento. */
    public function riattiva(Tenant $famiglia, ?MembroFamiglia $persona, User $accettatoDa): void
    {
        $this->famiglia = $famiglia;
        $this->persona = $persona;
        $this->accettatoDa = $accettatoDa;
        $this->revocatoIl = null;
        $this->createdAt = new \DateTimeImmutable();
    }
}
