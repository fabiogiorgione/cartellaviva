<?php

namespace App\Tenancy\Entity;

use App\Account\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Esportazione completa dei documenti di un tenant.
 *
 * Non esiste un file ZIP salvato: il link (token monouso, solo l'hash a DB)
 * genera lo ZIP al volo decifrando i documenti in memoria. Il link vale 24 ore
 * e una sola volta.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenant_export')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_export_token', columns: ['token_hash'])]
class TenantExport
{
    public const TTL = '+24 hours';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'richiesto_da_id', nullable: false, onDelete: 'CASCADE')]
    private User $richiestoDa;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $scadeIl;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scaricatoIl = null;

    public function __construct(Tenant $tenant, User $richiestoDa, string $tokenHash)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->richiestoDa = $richiestoDa;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->scadeIl = new \DateTimeImmutable(self::TTL);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getRichiestoDa(): User
    {
        return $this->richiestoDa;
    }

    public function getScadeIl(): \DateTimeImmutable
    {
        return $this->scadeIl;
    }

    public function isUsabile(): bool
    {
        return null === $this->scaricatoIl && $this->scadeIl > new \DateTimeImmutable();
    }

    public function markScaricato(): void
    {
        $this->scaricatoIl = new \DateTimeImmutable();
    }
}
