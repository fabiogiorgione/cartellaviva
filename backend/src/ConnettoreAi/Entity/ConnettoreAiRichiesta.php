<?php

namespace App\ConnettoreAi\Entity;

use App\Account\Entity\User;
use App\ConnettoreAi\Repository\ConnettoreAiRichiestaRepository;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Richiesta di abbinamento di un Mac (OAuth 2.0 Device Authorization Grant,
 * RFC 8628): il Mac tiene il device_code, l'utente approva lo user_code nell'app.
 */
#[ORM\Entity(repositoryClass: ConnettoreAiRichiestaRepository::class)]
#[ORM\Table(name: 'connettore_ai_richiesta')]
#[ORM\UniqueConstraint(name: 'uniq_connettore_ai_richiesta_device', columns: ['device_code_hash'])]
#[ORM\Index(name: 'idx_connettore_ai_richiesta_codice', columns: ['user_code'])]
class ConnettoreAiRichiesta
{
    public const IN_ATTESA = 'in_attesa';
    public const APPROVATA = 'approvata';
    public const NEGATA = 'negata';
    public const USATA = 'usata';

    public const DURATA_SECONDI = 600;
    public const INTERVALLO_SECONDI = 5;

    /** Lettere senza vocali né caratteri ambigui, come l'esempio della RFC 8628. */
    public const ALFABETO = 'BCDFGHJKLMNPQRSTVWXZ';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $deviceCodeHash;

    #[ORM\Column(length: 9)]
    private string $userCode;

    #[ORM\Column(length: 80)]
    private string $nomeProposto;

    #[ORM\Column(length: 10)]
    private string $stato = self::IN_ATTESA;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approvata_da_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $approvataDa = null;

    #[ORM\Column]
    private \DateTimeImmutable $scadeIl;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ultimoPollIl = null;

    public function __construct(string $deviceCodeHash, string $userCode, string $nomeProposto)
    {
        $this->id = Uuid::v7();
        $this->deviceCodeHash = $deviceCodeHash;
        $this->userCode = $userCode;
        $this->nomeProposto = $nomeProposto;
        $this->scadeIl = (new \DateTimeImmutable())->modify('+'.self::DURATA_SECONDI.' seconds');
    }

    public static function nuovoCodice(): string
    {
        $c = '';
        for ($i = 0; $i < 8; ++$i) {
            $c .= self::ALFABETO[random_int(0, \strlen(self::ALFABETO) - 1)];
        }

        return substr($c, 0, 4).'-'.substr($c, 4);
    }

    /** "wdjb mjht", "WDJBMJHT" o "WDJB-MJHT" diventano "WDJB-MJHT". */
    public static function normalizzaCodice(string $codice): ?string
    {
        $c = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $codice));

        return 8 === \strlen($c) ? substr($c, 0, 4).'-'.substr($c, 4) : null;
    }

    public function getUserCode(): string
    {
        return $this->userCode;
    }

    public function getNomeProposto(): string
    {
        return $this->nomeProposto;
    }

    public function getStato(): string
    {
        return $this->stato;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getApprovataDa(): ?User
    {
        return $this->approvataDa;
    }

    public function isScaduta(\DateTimeImmutable $ora): bool
    {
        return $this->scadeIl <= $ora;
    }

    public function getScadeIl(): \DateTimeImmutable
    {
        return $this->scadeIl;
    }

    public function approva(Tenant $tenant, User $chi, ?string $nome): void
    {
        $this->stato = self::APPROVATA;
        $this->tenant = $tenant;
        $this->approvataDa = $chi;
        if (null !== $nome && '' !== $nome) {
            $this->nomeProposto = $nome;
        }
    }

    public function nega(): void
    {
        $this->stato = self::NEGATA;
    }

    public function usata(): void
    {
        $this->stato = self::USATA;
    }

    /** Il Mac chiede troppo spesso? Registra comunque l'interrogazione (RFC 8628, slow_down). */
    public function troppoPresto(\DateTimeImmutable $ora): bool
    {
        $presto = null !== $this->ultimoPollIl && $this->ultimoPollIl > $ora->modify('-'.(self::INTERVALLO_SECONDI - 1).' seconds');
        $this->ultimoPollIl = $ora;

        return $presto;
    }
}
