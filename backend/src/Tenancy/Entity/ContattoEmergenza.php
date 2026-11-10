<?php

namespace App\Tenancy\Entity;

use App\Account\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Accesso d'emergenza (fase 7.5).
 *
 * 1. un proprietario indica un contatto fidato (con account CartellaViva), con
 *    le categorie visibili (o tutte) e i giorni di attesa (3-30);
 * 2. il contatto accetta dal link email, entrando con l'account della stessa
 *    casella: da quel momento PUÒ CHIEDERE l'accesso, ma non vede nulla;
 * 3. se lo chiede, partono N giorni di attesa con avviso a proprietari e membri,
 *    che possono opporsi dall'app o dal link nell'email;
 * 4. senza opposizione diventa membro con il ruolo "emergenza" (sola lettura,
 *    solo le categorie scelte), finché un proprietario non lo revoca.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contatto_emergenza')]
#[ORM\Index(name: 'idx_emergenza_richiesta', columns: ['richiesta_stato', 'eseguibile_il'])]
#[ORM\UniqueConstraint(name: 'uniq_emergenza_invito', columns: ['invito_token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_emergenza_annulla', columns: ['annulla_token_hash'])]
class ContattoEmergenza
{
    public const INVITATO = 'invitato';
    public const ATTIVO = 'attivo';
    public const RIFIUTATO = 'rifiutato';
    public const REVOCATO = 'revocato';

    public const IN_ATTESA = 'in_attesa';
    public const CONCESSA = 'concessa';
    public const NEGATA = 'negata';

    public const MAX_PER_FAMIGLIA = 3;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $configuratoDa;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $contatto = null;

    /** @var list<string>|null id delle categorie visibili; null = tutte */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $categorie;

    #[ORM\Column]
    private int $giorniAttesa;

    #[ORM\Column(length: 10)]
    private string $stato = self::INVITATO;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $invitoTokenHash;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invitoScadenza;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $richiestaStato = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $richiestaIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $eseguibileIl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $motivo = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $annullaTokenHash = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $negataDa = null;

    #[ORM\ManyToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Membership $membership = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param list<string>|null $categorie */
    public function __construct(Tenant $tenant, User $configuratoDa, string $email, ?array $categorie, int $giorniAttesa, string $invitoTokenHash)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->configuratoDa = $configuratoDa;
        $this->email = User::normalizeEmail($email);
        $this->categorie = $categorie;
        $this->giorniAttesa = $giorniAttesa;
        $this->invitoTokenHash = $invitoTokenHash;
        $this->invitoScadenza = new \DateTimeImmutable('+7 days');
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

    public function getConfiguratoDa(): ?User
    {
        return $this->configuratoDa;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getContatto(): ?User
    {
        return $this->contatto;
    }

    /** @return list<string>|null */
    public function getCategorie(): ?array
    {
        return $this->categorie;
    }

    /** Categoria eliminata: si toglie dall'elenco (vuoto resta "nessuna", mai "tutte"). */
    public function togliCategoria(string $categoriaId): void
    {
        if (null !== $this->categorie) {
            $this->categorie = array_values(array_diff($this->categorie, [$categoriaId]));
        }
    }

    public function getGiorniAttesa(): int
    {
        return $this->giorniAttesa;
    }

    public function getStato(): string
    {
        return $this->stato;
    }

    /** Stato della richiesta; "concessa" con l'appartenenza rimossa a mano vale come chiusa. */
    public function getRichiestaStato(): ?string
    {
        return self::CONCESSA === $this->richiestaStato && null === $this->membership ? null : $this->richiestaStato;
    }

    public function getRichiestaIl(): ?\DateTimeImmutable
    {
        return $this->richiestaIl;
    }

    public function getEseguibileIl(): ?\DateTimeImmutable
    {
        return $this->eseguibileIl;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getNegataDa(): ?User
    {
        return $this->negataDa;
    }

    public function getMembership(): ?Membership
    {
        return $this->membership;
    }

    public function isInvitoValido(): bool
    {
        return self::INVITATO === $this->stato && null !== $this->invitoScadenza && $this->invitoScadenza > new \DateTimeImmutable();
    }

    public function accetta(User $contatto): void
    {
        $this->contatto = $contatto;
        $this->stato = self::ATTIVO;
        $this->invitoTokenHash = null;
        $this->invitoScadenza = null;
    }

    public function rifiuta(): void
    {
        $this->stato = self::RIFIUTATO;
        $this->invitoTokenHash = null;
    }

    public function puoChiedere(): bool
    {
        return self::ATTIVO === $this->stato && !\in_array($this->getRichiestaStato(), [self::IN_ATTESA, self::CONCESSA], true);
    }

    public function richiedi(?string $motivo, string $annullaTokenHash): void
    {
        $this->richiestaStato = self::IN_ATTESA;
        $this->richiestaIl = new \DateTimeImmutable();
        $this->eseguibileIl = new \DateTimeImmutable(sprintf('+%d days', $this->giorniAttesa));
        $this->motivo = $motivo;
        $this->annullaTokenHash = $annullaTokenHash;
        $this->negataDa = null;
    }

    public function nega(?User $da): void
    {
        $this->richiestaStato = self::NEGATA;
        $this->negataDa = $da;
        $this->annullaTokenHash = null;
    }

    public function concedi(Membership $membership): void
    {
        $this->richiestaStato = self::CONCESSA;
        $this->membership = $membership;
        $this->annullaTokenHash = null;
    }

    /** Revoca del contatto: l'eventuale accesso concesso viene tolto dal servizio. */
    public function revoca(): void
    {
        $this->stato = self::REVOCATO;
        $this->invitoTokenHash = null;
        $this->annullaTokenHash = null;
        if (self::IN_ATTESA === $this->richiestaStato) {
            $this->richiestaStato = self::NEGATA;
        }
        $this->membership = null;
    }
}
