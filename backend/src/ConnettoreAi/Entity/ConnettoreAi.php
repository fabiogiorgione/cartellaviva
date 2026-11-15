<?php

namespace App\ConnettoreAi\Entity;

use App\Account\Entity\User;
use App\ConnettoreAi\Repository\ConnettoreAiRepository;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un Mac collegato a una famiglia che esegue le analisi AI al posto del server
 * (docs/connettore-ai.md). A database solo gli hash dei token.
 */
#[ORM\Entity(repositoryClass: ConnettoreAiRepository::class)]
#[ORM\Table(name: 'connettore_ai')]
#[ORM\Index(name: 'idx_connettore_ai_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_connettore_ai_access', columns: ['access_token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_connettore_ai_refresh', columns: ['refresh_token_hash'])]
#[ORM\Index(name: 'idx_connettore_ai_refresh_precedente', columns: ['refresh_precedente_hash'])]
#[PermissionResource('connettore_ai', 'AI sul Mac della famiglia', [
    'manage' => 'Collega, rinomina e scollega i Mac che fanno le analisi AI per la famiglia',
], sensitive: true)]
class ConnettoreAi
{
    /** Senza contatto da più di così, il connettore non è online. */
    public const ONLINE_SECONDI = 90;
    public const ACCESSO_SECONDI = 3600;
    public const RINNOVO_GIORNI = 30;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 80)]
    private string $nome;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approvato_da_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvatoDa;

    #[ORM\Column]
    private \DateTimeImmutable $approvatoIl;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revocatoIl = null;

    #[ORM\Column(length: 64)]
    private string $accessTokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $accessScadeIl;

    #[ORM\Column(length: 64)]
    private string $refreshTokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $refreshScadeIl;

    /** L'ultimo refresh token consumato: se torna, qualcuno l'ha copiato (si revoca). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $refreshPrecedenteHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ultimoContatto = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $versione = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sistema = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $chip = null;

    #[ORM\Column(nullable: true)]
    private ?int $memoriaGb = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $motore = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $modello = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $modelloDigest = null;

    #[ORM\Column(nullable: true)]
    private ?int $contesto = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $lavoriFatti = 0;

    #[ORM\Column(nullable: true)]
    private ?int $durataMediaMs = null;

    /** Il token di accesso e il refresh token si generano fuori ({@see \App\ConnettoreAi\ConnettoriAi}); qui solo gli hash. */
    public function __construct(Tenant $tenant, string $nome, User $approvatoDa, string $accessTokenHash, string $refreshTokenHash, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->tenant = $tenant;
        $this->nome = $nome;
        $this->approvatoDa = $approvatoDa;
        $this->approvatoIl = new \DateTimeImmutable();
        $this->accessTokenHash = $accessTokenHash;
        $this->accessScadeIl = $this->approvatoIl->modify('+'.self::ACCESSO_SECONDI.' seconds');
        $this->refreshTokenHash = $refreshTokenHash;
        $this->refreshScadeIl = $this->approvatoIl->modify('+'.self::RINNOVO_GIORNI.' days');
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

    public function rinomina(string $nome): void
    {
        $this->nome = $nome;
    }

    public function getApprovatoDa(): ?User
    {
        return $this->approvatoDa;
    }

    public function getApprovatoIl(): \DateTimeImmutable
    {
        return $this->approvatoIl;
    }

    public function isRevocato(): bool
    {
        return null !== $this->revocatoIl;
    }

    public function revoca(): void
    {
        $this->revocatoIl ??= new \DateTimeImmutable();
    }

    public function accessoValido(\DateTimeImmutable $ora): bool
    {
        return !$this->isRevocato() && $this->accessScadeIl > $ora;
    }

    public function rinnovoValido(\DateTimeImmutable $ora): bool
    {
        return !$this->isRevocato() && $this->refreshScadeIl > $ora;
    }

    /** Nuovi token dopo un rinnovo: il refresh token cambia, la sua scadenza no. */
    public function ruotaToken(string $accessTokenHash, string $refreshTokenHash): void
    {
        $this->accessTokenHash = $accessTokenHash;
        $this->accessScadeIl = (new \DateTimeImmutable())->modify('+'.self::ACCESSO_SECONDI.' seconds');
        $this->refreshPrecedenteHash = $this->refreshTokenHash;
        $this->refreshTokenHash = $refreshTokenHash;
    }

    public function getRefreshScadeIl(): \DateTimeImmutable
    {
        return $this->refreshScadeIl;
    }

    public function contatto(): void
    {
        $this->ultimoContatto = new \DateTimeImmutable();
    }

    public function getUltimoContatto(): ?\DateTimeImmutable
    {
        return $this->ultimoContatto;
    }

    public function isOnline(\DateTimeImmutable $ora): bool
    {
        return !$this->isRevocato() && null !== $this->ultimoContatto && $this->ultimoContatto > $ora->modify('-'.self::ONLINE_SECONDI.' seconds');
    }

    /** @param array{versione: ?string, sistema: ?string, chip: ?string, memoriaGb: ?int, motore: ?string, modello: ?string, digest: ?string, contesto: ?int} $d */
    public function presenta(array $d): void
    {
        $this->versione = $d['versione'];
        $this->sistema = $d['sistema'];
        $this->chip = $d['chip'];
        $this->memoriaGb = $d['memoriaGb'];
        $this->motore = $d['motore'];
        $this->modello = $d['modello'];
        $this->modelloDigest = $d['digest'];
        $this->contesto = $d['contesto'];
    }

    public function lavoroFatto(?int $durataMs): void
    {
        if (null !== $durataMs && $durataMs > 0) {
            // Media mobile: pesa di più l'andamento recente (modello o Mac cambiati).
            $this->durataMediaMs = null === $this->durataMediaMs ? $durataMs : (int) round($this->durataMediaMs * 0.8 + $durataMs * 0.2);
        }
        ++$this->lavoriFatti;
    }

    /** @return array<string, mixed> */
    public function vista(\DateTimeImmutable $ora): array
    {
        return [
            'id' => (string) $this->id,
            'nome' => $this->nome,
            'online' => $this->isOnline($ora),
            'ultimoContatto' => $this->ultimoContatto?->format(\DATE_ATOM),
            'approvatoDa' => $this->approvatoDa?->getFullName(),
            'approvatoIl' => $this->approvatoIl->format(\DATE_ATOM),
            'versione' => $this->versione,
            'sistema' => $this->sistema,
            'chip' => $this->chip,
            'memoriaGb' => $this->memoriaGb,
            'motore' => $this->motore,
            'modello' => $this->modello,
            'contesto' => $this->contesto,
            'lavoriFatti' => $this->lavoriFatti,
            'durataMediaMs' => $this->durataMediaMs,
        ];
    }
}
