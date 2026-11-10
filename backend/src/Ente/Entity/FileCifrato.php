<?php

namespace App\Ente\Entity;

use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * File dell'ente fuori dall'archivio documenti: PDF allegati alle richieste e
 * copie ricevute dalle famiglie. Sempre cifrato con una chiave propria (DEK)
 * avvolta dalla chiave del tenant ENTE; si legge solo dalle API delle
 * richieste, con l'ambito per gruppo (non compare mai tra i documenti).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ente_file')]
class FileCifrato implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 255)]
    private string $storageKey;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $dimensione;

    #[ORM\Column(length: 255)]
    private string $nomeFile;

    #[ORM\Column(type: 'text')]
    private string $encryptedDek;

    #[ORM\Column]
    private int $keyVersion;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Copia per la visualizzazione (Office → PDF, HEIC → JPEG), cifrata con la stessa DEK; creata alla prima apertura. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $anteprimaKey = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $anteprimaMime = null;

    public function __construct(Uuid $id, Tenant $tenant, string $storageKey, string $mimeType, int $dimensione, string $nomeFile, string $encryptedDek, int $keyVersion)
    {
        $this->id = $id;
        $this->tenant = $tenant;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->dimensione = $dimensione;
        $this->nomeFile = $nomeFile;
        $this->encryptedDek = $encryptedDek;
        $this->keyVersion = $keyVersion;
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

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getDimensione(): int
    {
        return $this->dimensione;
    }

    public function getNomeFile(): string
    {
        return $this->nomeFile;
    }

    public function getEncryptedDek(): string
    {
        return $this->encryptedDek;
    }

    public function getKeyVersion(): int
    {
        return $this->keyVersion;
    }

    public function rewrapDek(string $encryptedDek, int $keyVersion): void
    {
        $this->encryptedDek = $encryptedDek;
        $this->keyVersion = $keyVersion;
    }

    public function getAnteprimaKey(): ?string
    {
        return $this->anteprimaKey;
    }

    public function getAnteprimaMime(): ?string
    {
        return $this->anteprimaMime;
    }

    public function setAnteprima(string $key, string $mime): void
    {
        $this->anteprimaKey = $key;
        $this->anteprimaMime = $mime;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
