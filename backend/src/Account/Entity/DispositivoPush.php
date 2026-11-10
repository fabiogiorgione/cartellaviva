<?php

namespace App\Account\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Telefono registrato per le notifiche push (token FCM). Un token appartiene
 * a un solo utente: se un altro accede dallo stesso telefono, il token passa
 * a lui. Al logout l'app lo rimuove; FCM ci dice quando non vale più.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dispositivo_push')]
#[ORM\UniqueConstraint(name: 'uniq_dispositivo_token', columns: ['token'])]
class DispositivoPush
{
    public const PIATTAFORME = ['android', 'ios'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 512)]
    private string $token;

    #[ORM\Column(length: 10)]
    private string $piattaforma;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $aggiornatoIl;

    public function __construct(User $user, string $token, string $piattaforma)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->token = $token;
        $this->piattaforma = $piattaforma;
        $this->createdAt = new \DateTimeImmutable();
        $this->aggiornatoIl = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPiattaforma(): string
    {
        return $this->piattaforma;
    }

    public function assegnaA(User $user, string $piattaforma): void
    {
        $this->user = $user;
        $this->piattaforma = $piattaforma;
        $this->aggiornatoIl = new \DateTimeImmutable();
    }
}
