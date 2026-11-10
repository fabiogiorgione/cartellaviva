<?php

namespace App\Account\Entity;

use App\Account\Repository\TwoFactorChallengeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Secondo passo del login: dopo password (o Google) corrette, se l'utente ha la
 * 2FA attiva non si emettono token ma una challenge monouso di pochi minuti.
 * Salvata a DB (non in sessione: l'API è stateless e può girare su più istanze).
 */
#[ORM\Entity(repositoryClass: TwoFactorChallengeRepository::class)]
#[ORM\Table(name: 'two_factor_challenge')]
#[ORM\UniqueConstraint(name: 'uniq_two_factor_challenge_token', columns: ['token_hash'])]
class TwoFactorChallenge
{
    public const TTL_SECONDS = 300;
    public const MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /** Primo fattore usato: "password" o "google". */
    #[ORM\Column(length: 20)]
    private string $method;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $plainToken, string $method)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = self::hash($plainToken);
        $this->method = $method;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify(sprintf('+%d seconds', self::TTL_SECONDS));
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function isUsable(): bool
    {
        return null === $this->consumedAt && $this->attempts < self::MAX_ATTEMPTS && $this->expiresAt > new \DateTimeImmutable();
    }

    public function registerFailedAttempt(): void
    {
        ++$this->attempts;
    }

    public function getRemainingAttempts(): int
    {
        return max(0, self::MAX_ATTEMPTS - $this->attempts);
    }

    public function consume(): void
    {
        $this->consumedAt = new \DateTimeImmutable();
    }
}
