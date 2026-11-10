<?php

namespace App\Shared\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Impronta (NON la chiave) di ogni versione della master key: serve a rifiutare
 * un file di chiave sbagliato. Scritta da App\Shared\Crypto\KeyVersionGuard.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'crypto_key_version')]
class CryptoKeyVersion
{
    #[ORM\Id]
    #[ORM\Column]
    private int $version;

    #[ORM\Column(length: 16)]
    private string $fingerprint;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
