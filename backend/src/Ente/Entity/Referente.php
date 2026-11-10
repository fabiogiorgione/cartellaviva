<?php

namespace App\Ente\Entity;

use App\Ente\Enum\ReferenteStato;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Genitore, tutore o l'iscritto stesso se adulto: chi risponde alle richieste.
 * Un iscritto può avere più referenti in famiglie diverse (es. genitori separati).
 *
 * L'invito a collegarsi parte solo quando un operatore lo conferma; il token
 * (a DB solo l'hash) vale per accettare, rifiutare o dire "non sono io".
 */
#[ORM\Entity]
#[ORM\Table(name: 'ente_referente')]
#[ORM\UniqueConstraint(name: 'uniq_ente_referente_token', columns: ['token_hash'])]
class Referente implements TenantScopedInterface
{
    public const INVITO_TTL = '+30 days';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Iscritto::class, inversedBy: 'referenti')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Iscritto $iscritto;

    #[ORM\Column(length: 100)]
    private string $nome;

    #[ORM\Column(length: 100)]
    private string $cognome;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email;

    /** Testo libero: "madre", "padre", "tutore"... */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $relazione;

    #[ORM\Column(length: 20, enumType: ReferenteStato::class)]
    private ReferenteStato $stato = ReferenteStato::DA_INVITARE;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tokenScadenza = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invitatoIl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Iscritto $iscritto, string $nome, string $cognome, ?string $email, ?string $relazione = null)
    {
        $this->id = Uuid::v7();
        $this->iscritto = $iscritto;
        $this->nome = $nome;
        $this->cognome = $cognome;
        $this->email = $email;
        $this->relazione = $relazione;
        $this->createdAt = new \DateTimeImmutable();
        $iscritto->aggiungiReferente($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->iscritto->getTenant();
    }

    public function getIscritto(): Iscritto
    {
        return $this->iscritto;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getCognome(): string
    {
        return $this->cognome;
    }

    public function getNomeCompleto(): string
    {
        return trim($this->nome.' '.$this->cognome);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getRelazione(): ?string
    {
        return $this->relazione;
    }

    public function getStato(): ReferenteStato
    {
        return $this->stato;
    }

    public function getInvitatoIl(): ?\DateTimeImmutable
    {
        return $this->invitatoIl;
    }

    /** Cambiare l'email annulla l'invito in corso (andava a un'altra casella). */
    public function aggiorna(string $nome, string $cognome, ?string $email, ?string $relazione): void
    {
        if ($email !== $this->email && \in_array($this->stato, [ReferenteStato::INVITATO, ReferenteStato::NON_SONO_IO, ReferenteStato::RIFIUTATO], true)) {
            $this->stato = ReferenteStato::DA_INVITARE;
            $this->tokenHash = null;
            $this->tokenScadenza = null;
        }
        $this->nome = $nome;
        $this->cognome = $cognome;
        $this->email = $email;
        $this->relazione = $relazione;
    }

    public function isInvitabile(): bool
    {
        return null !== $this->email && \in_array($this->stato, [ReferenteStato::DA_INVITARE, ReferenteStato::INVITATO, ReferenteStato::REVOCATO], true);
    }

    public function invita(string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
        $this->tokenScadenza = new \DateTimeImmutable(self::INVITO_TTL);
        $this->invitatoIl = new \DateTimeImmutable();
        $this->stato = ReferenteStato::INVITATO;
    }

    public function isInvitoValido(): bool
    {
        return ReferenteStato::INVITATO === $this->stato && null !== $this->tokenScadenza && $this->tokenScadenza > new \DateTimeImmutable();
    }

    /** Esito dell'invito: il token non vale più. */
    public function concludiInvito(ReferenteStato $stato): void
    {
        $this->stato = $stato;
        $this->tokenHash = null;
        $this->tokenScadenza = null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
