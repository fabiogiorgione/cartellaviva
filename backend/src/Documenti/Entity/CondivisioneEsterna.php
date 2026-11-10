<?php

namespace App\Documenti\Entity;

use App\Account\Entity\User;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Shared\Entity\Contract\TenantOwnedTrait;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Condivisione esterna temporanea (fase 7.2): uno o più documenti per chi non
 * ha un account (medico, commercialista, agenzia).
 *
 * - due segreti su canali diversi: il LINK (token, a DB solo l'hash) e il
 *   CODICE (a DB solo un hash lento), che chi condivide comunica a parte;
 * - scade (da 1 ora a 30 giorni), ha un numero massimo di download, si
 *   revoca in ogni momento; 5 codici errati la bloccano;
 * - nessuna email del destinatario: "nota" è solo un promemoria per chi condivide;
 * - dopo il codice, una sessione di 30 minuti (header X-Condivisione-Sessione).
 */
#[ORM\Entity]
#[ORM\Table(name: 'condivisione_esterna')]
#[ORM\UniqueConstraint(name: 'uniq_condivisione_token', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_condivisione_scadenza', columns: ['scade_il'])]
#[PermissionResource('condivisione', 'Condivisioni esterne', [
    'manage:own' => 'Condivide documenti con persone esterne tramite link e codice, e revoca le proprie condivisioni',
    'manage:all' => 'Vede e revoca tutte le condivisioni esterne del tenant',
])]
class CondivisioneEsterna implements OwnedResourceInterface
{
    use TenantOwnedTrait;

    public const MAX_TENTATIVI = 5;
    public const SESSIONE = '+30 minutes';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** @var Collection<int, Documento> */
    #[ORM\ManyToMany(targetEntity: Documento::class)]
    #[ORM\JoinTable(name: 'condivisione_esterna_documento')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $documenti;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 255)]
    private string $codiceHash;

    /** Promemoria per chi condivide (es. "Dott. Bianchi"): mai mostrato al destinatario. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $nota;

    #[ORM\Column]
    private \DateTimeImmutable $scadeIl;

    #[ORM\Column]
    private int $maxDownload;

    #[ORM\Column]
    private int $download = 0;

    #[ORM\Column]
    private int $tentativiErrati = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sessioneHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sessioneScadenza = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $primoAccessoIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ultimoAccessoIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revocataIl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param list<Documento> $documenti */
    public function __construct(Tenant $tenant, User $owner, array $documenti, string $tokenHash, string $codiceHash, \DateTimeImmutable $scadeIl, int $maxDownload, ?string $nota)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->documenti = new ArrayCollection($documenti);
        $this->tokenHash = $tokenHash;
        $this->codiceHash = $codiceHash;
        $this->scadeIl = $scadeIl;
        $this->maxDownload = $maxDownload;
        $this->nota = $nota;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /** @return list<Documento> */
    public function getDocumenti(): array
    {
        return array_values($this->documenti->toArray());
    }

    public function getCodiceHash(): string
    {
        return $this->codiceHash;
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function getScadeIl(): \DateTimeImmutable
    {
        return $this->scadeIl;
    }

    public function getMaxDownload(): int
    {
        return $this->maxDownload;
    }

    public function getDownload(): int
    {
        return $this->download;
    }

    public function getPrimoAccessoIl(): ?\DateTimeImmutable
    {
        return $this->primoAccessoIl;
    }

    public function getUltimoAccessoIl(): ?\DateTimeImmutable
    {
        return $this->ultimoAccessoIl;
    }

    public function getRevocataIl(): ?\DateTimeImmutable
    {
        return $this->revocataIl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** attiva | scaduta | esaurita | bloccata | revocata */
    public function getStato(): string
    {
        return match (true) {
            null !== $this->revocataIl => 'revocata',
            $this->tentativiErrati >= self::MAX_TENTATIVI => 'bloccata',
            $this->scadeIl <= new \DateTimeImmutable() => 'scaduta',
            $this->download >= $this->maxDownload => 'esaurita',
            default => 'attiva',
        };
    }

    public function isAttiva(): bool
    {
        return 'attiva' === $this->getStato();
    }

    public function codiceErrato(): void
    {
        ++$this->tentativiErrati;
    }

    /** Codice giusto: nuova sessione (la precedente non vale più). */
    public function apriSessione(string $sessioneHash): void
    {
        $ora = new \DateTimeImmutable();
        $this->tentativiErrati = 0;
        $this->sessioneHash = $sessioneHash;
        $this->sessioneScadenza = min(new \DateTimeImmutable(self::SESSIONE), $this->scadeIl);
        $this->primoAccessoIl ??= $ora;
        $this->ultimoAccessoIl = $ora;
    }

    public function isSessioneValida(string $sessioneHash): bool
    {
        return $this->isAttiva() && null !== $this->sessioneHash && hash_equals($this->sessioneHash, $sessioneHash)
            && null !== $this->sessioneScadenza && $this->sessioneScadenza > new \DateTimeImmutable();
    }

    public function contaDownload(): void
    {
        ++$this->download;
        $this->ultimoAccessoIl = new \DateTimeImmutable();
    }

    public function revoca(): void
    {
        $this->revocataIl ??= new \DateTimeImmutable();
        $this->sessioneHash = null;
    }
}
