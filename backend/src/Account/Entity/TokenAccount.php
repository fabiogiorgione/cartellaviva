<?php

namespace App\Account\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Link monouso inviati per email per la gestione dell'account:
 * - RESET_PASSWORD: "password dimenticata" (1 ora);
 * - VERIFICA_EMAIL: verifica dell'indirizzo dopo la registrazione (24 ore);
 * - CAMBIO_EMAIL: conferma del nuovo indirizzo (24 ore, inviato al nuovo);
 * - ATTIVAZIONE: primo accesso di un account creato dal portale (7 giorni).
 *
 * A DB solo l'hash SHA-256 del token. Un nuovo token dello stesso tipo
 * invalida i precedenti.
 */
#[ORM\Entity]
#[ORM\Table(name: 'token_account')]
#[ORM\UniqueConstraint(name: 'uniq_token_account_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_token_account_user', columns: ['user_id', 'tipo', 'created_at'])]
class TokenAccount
{
    public const RESET_PASSWORD = 'reset_password';
    public const VERIFICA_EMAIL = 'verifica_email';
    public const CAMBIO_EMAIL = 'cambio_email';
    public const ATTIVAZIONE = 'attivazione';

    public const DURATE = [
        self::RESET_PASSWORD => '+1 hour',
        self::VERIFICA_EMAIL => '+24 hours',
        self::CAMBIO_EMAIL => '+24 hours',
        self::ATTIVAZIONE => '+7 days',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $tipo;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /** Solo per CAMBIO_EMAIL: l'indirizzo da confermare. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $nuovaEmail;

    #[ORM\Column]
    private \DateTimeImmutable $scadenza;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usatoIl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $tipo, string $tokenHash, ?string $nuovaEmail = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tipo = $tipo;
        $this->tokenHash = $tokenHash;
        $this->nuovaEmail = $nuovaEmail;
        $this->createdAt = new \DateTimeImmutable();
        $this->scadenza = new \DateTimeImmutable(self::DURATE[$tipo]);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getNuovaEmail(): ?string
    {
        return $this->nuovaEmail;
    }

    public function isUsabile(): bool
    {
        return null === $this->usatoIl && $this->scadenza > new \DateTimeImmutable();
    }

    public function usa(): void
    {
        $this->usatoIl = new \DateTimeImmutable();
    }
}
