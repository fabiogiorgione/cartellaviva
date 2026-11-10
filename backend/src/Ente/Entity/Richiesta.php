<?php

namespace App\Ente\Entity;

use App\Account\Entity\User;
use App\Ente\Enum\RichiestaTipo;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Richiesta dell'ente (firma, invio di un documento, presa visione) a un
 * gruppo o a una selezione di iscritti. Per ogni famiglia raggiungibile c'è
 * un RichiestaDestinatario: ogni famiglia vede solo la propria.
 *
 * Visibilità nell'ente: segreteria (ente:tutti_i_gruppi), operatori del gruppo
 * a cui è indirizzata, chi l'ha creata.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ente_richiesta')]
#[ORM\Index(name: 'idx_ente_richiesta_tenant', columns: ['tenant_id', 'created_at'])]
#[PermissionResource('richiesta_ente', 'Richieste alle famiglie', [
    'read' => 'Vede le richieste (dei propri gruppi) e le risposte',
    'send' => 'Crea, invia, sollecita e annulla richieste',
    'verify' => 'Verifica le risposte (accetta o chiede di rifirmare)',
], sensitive: true)]
class Richiesta implements TenantScopedInterface
{
    public const FIRMATARI_TUTTI = 'tutti';
    public const FIRMATARI_UNO = 'uno';

    /** Firma su carta: stampa, firma, scansione (verifica dell'ente). */
    public const FIRMA_CARTACEA = 'cartacea';
    /** Firma elettronica semplice con codice email, ogni referente per sé. */
    public const FIRMA_CODICE = 'codice';

    #[ORM\Column(length: 10, options: ['default' => 'cartacea'])]
    private string $modalitaFirma = self::FIRMA_CARTACEA;

    public function getModalitaFirma(): string
    {
        return $this->modalitaFirma;
    }

    public function isFirmaConCodice(): bool
    {
        return RichiestaTipo::FIRMA === $this->tipo && self::FIRMA_CODICE === $this->modalitaFirma;
    }

    public function setModalitaFirma(string $modalita): void
    {
        if (!\in_array($modalita, [self::FIRMA_CARTACEA, self::FIRMA_CODICE], true)) {
            throw new \InvalidArgumentException('Modalità di firma non valida.');
        }
        // Solo le richieste di firma hanno una modalità: le altre restano "cartacee" (senza effetto).
        $this->modalitaFirma = RichiestaTipo::FIRMA === $this->tipo ? $modalita : self::FIRMA_CARTACEA;
    }

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 20, enumType: RichiestaTipo::class)]
    private RichiestaTipo $tipo;

    #[ORM\Column(length: 200)]
    private string $titolo;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $istruzioni;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $entroIl;

    #[ORM\Column(length: 10)]
    private string $firmatari;

    #[ORM\ManyToOne(targetEntity: Gruppo::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Gruppo $gruppo;

    #[ORM\ManyToOne(targetEntity: FileCifrato::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FileCifrato $allegato;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creatoDa;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $annullataIl = null;

    #[ORM\ManyToOne(targetEntity: ModelloRichiesta::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ModelloRichiesta $modello = null;

    /** Chi consegna indica fino a quando vale il documento. */
    #[ORM\Column(options: ['default' => false])]
    private bool $chiediValidita = false;

    /** Rinnovo automatico N giorni prima della scadenza del documento consegnato. */
    #[ORM\Column(nullable: true)]
    private ?int $rinnovoGiorni = null;

    /** Le copie ricevute si eliminano N mesi dopo la conclusione (null = senza limite). */
    #[ORM\Column(nullable: true)]
    private ?int $conservazioneMesi = null;

    /** Rinnovo automatico: la consegna da cui nasce. */
    #[ORM\ManyToOne(targetEntity: RichiestaDestinatario::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RichiestaDestinatario $rinnovoDi = null;

    public function impostaRegole(?ModelloRichiesta $modello, bool $chiediValidita, ?int $rinnovoGiorni, ?int $conservazioneMesi, ?RichiestaDestinatario $rinnovoDi = null): void
    {
        $this->modello = $modello;
        $this->chiediValidita = $chiediValidita || null !== $rinnovoGiorni;
        $this->rinnovoGiorni = $rinnovoGiorni;
        $this->conservazioneMesi = $conservazioneMesi;
        $this->rinnovoDi = $rinnovoDi;
    }

    public function getModello(): ?ModelloRichiesta
    {
        return $this->modello;
    }

    public function isChiediValidita(): bool
    {
        return $this->chiediValidita;
    }

    public function getRinnovoGiorni(): ?int
    {
        return $this->rinnovoGiorni;
    }

    public function getConservazioneMesi(): ?int
    {
        return $this->conservazioneMesi;
    }

    public function getRinnovoDi(): ?RichiestaDestinatario
    {
        return $this->rinnovoDi;
    }

    /** Iscritti senza referenti collegati al momento dell'invio (nomi, per l'avanzamento). */
    #[ORM\Column(type: Types::JSON)]
    private array $nonRaggiunti = [];

    /** @var Collection<int, RichiestaDestinatario> */
    #[ORM\OneToMany(targetEntity: RichiestaDestinatario::class, mappedBy: 'richiesta', cascade: ['persist', 'remove'])]
    private Collection $destinatari;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, RichiestaTipo $tipo, string $titolo, ?string $istruzioni, ?\DateTimeImmutable $entroIl, string $firmatari, ?Gruppo $gruppo, ?FileCifrato $allegato, ?User $creatoDa)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->tipo = $tipo;
        $this->titolo = $titolo;
        $this->istruzioni = $istruzioni;
        $this->entroIl = $entroIl;
        $this->firmatari = $firmatari;
        $this->gruppo = $gruppo;
        $this->allegato = $allegato;
        $this->creatoDa = $creatoDa;
        $this->destinatari = new ArrayCollection();
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

    public function getTipo(): RichiestaTipo
    {
        return $this->tipo;
    }

    public function getTitolo(): string
    {
        return $this->titolo;
    }

    public function getIstruzioni(): ?string
    {
        return $this->istruzioni;
    }

    public function getEntroIl(): ?\DateTimeImmutable
    {
        return $this->entroIl;
    }

    public function getFirmatari(): string
    {
        return $this->firmatari;
    }

    public function bastaUno(): bool
    {
        return self::FIRMATARI_UNO === $this->firmatari;
    }

    public function getGruppo(): ?Gruppo
    {
        return $this->gruppo;
    }

    public function getAllegato(): ?FileCifrato
    {
        return $this->allegato;
    }

    public function getCreatoDa(): ?User
    {
        return $this->creatoDa;
    }

    public function isAnnullata(): bool
    {
        return null !== $this->annullataIl;
    }

    public function annulla(): void
    {
        $this->annullataIl ??= new \DateTimeImmutable();
    }

    /** @return list<string> */
    public function getNonRaggiunti(): array
    {
        return $this->nonRaggiunti;
    }

    /** @param list<string> $nomi */
    public function setNonRaggiunti(array $nomi): void
    {
        $this->nonRaggiunti = $nomi;
    }

    /** @return Collection<int, RichiestaDestinatario> */
    public function getDestinatari(): Collection
    {
        return $this->destinatari;
    }

    public function aggiungiDestinatario(RichiestaDestinatario $d): void
    {
        $this->destinatari->add($d);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
