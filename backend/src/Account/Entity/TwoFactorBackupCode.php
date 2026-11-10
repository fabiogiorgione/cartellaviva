<?php

namespace App\Account\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Codici di recupero monouso, mostrati una
 * sola volta all'attivazione. A DB solo l'hash (password_hash).
 */
#[ORM\Entity]
#[ORM\Table(name: 'two_factor_backup_code')]
class TwoFactorBackupCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $codeHash;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(User $user, string $plainCode)
    {
        $this->user = $user;
        $this->codeHash = password_hash(self::normalize($plainCode), \PASSWORD_DEFAULT);
    }

    public static function normalize(string $code): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code));
    }

    public function matches(string $plainCode): bool
    {
        return null === $this->usedAt && password_verify(self::normalize($plainCode), $this->codeHash);
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }
}
