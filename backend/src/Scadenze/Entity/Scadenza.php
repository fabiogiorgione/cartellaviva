<?php

namespace App\Scadenze\Entity;

use App\Account\Entity\User;
use App\Documenti\Entity\Categoria;
use App\Documenti\Entity\Documento;
use App\Ente\Entity\RichiestaDestinatario;
use App\Scadenze\Enum\ScadenzaOrigine;
use App\Scadenze\Enum\ScadenzaRicorrenza;
use App\Scadenze\Enum\ScadenzaStato;
use App\Scadenze\Repository\ScadenzaRepository;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Shared\Entity\Contract\TenantOwnedTrait;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Scadenza della famiglia (bolletta, revisione, documento d'identità…).
 *
 * - owner = chi l'ha creata: con scadenza:manage:own la gestisce solo lui;
 * - ricorrente: segnandola come fatta si crea la successiva;
 * - promemoria: un'email N giorni prima, inviata dal pianificatore una sola volta
 *   (si riattiva se cambiano data o anticipo);
 * - documento e persona sono facoltativi; se il documento viene eliminato la
 *   scadenza resta, con l'indicazione "documento eliminato".
 */
#[ORM\Entity(repositoryClass: ScadenzaRepository::class)]
#[ORM\Table(name: 'scadenza')]
#[ORM\Index(name: 'idx_scadenza_tenant_data', columns: ['tenant_id', 'data'])]
#[ORM\Index(name: 'idx_scadenza_promemoria', columns: ['stato', 'promemoria_il'])]
#[ORM\HasLifecycleCallbacks]
#[PermissionResource('scadenza', 'Scadenze', [
    'read' => 'Visualizza lo scadenziario',
    'manage:own' => 'Crea e gestisce le proprie scadenze',
    'manage:all' => 'Gestisce tutte le scadenze del tenant',
], sensitive: false)]
class Scadenza implements OwnedResourceInterface
{
    use TenantOwnedTrait;
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    private string $titolo;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $data;

    #[ORM\Column(length: 20, enumType: ScadenzaRicorrenza::class)]
    private ScadenzaRicorrenza $ricorrenza = ScadenzaRicorrenza::NESSUNA;

    /** Giorni di anticipo del promemoria (0 = il giorno stesso); null = nessun promemoria. */
    #[ORM\Column(nullable: true)]
    private ?int $promemoriaGiorni = null;

    /** Giorno in cui inviare il promemoria (calcolato): indicizzato per il pianificatore. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $promemoriaIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $promemoriaInviatoIl = null;

    #[ORM\ManyToOne(targetEntity: Documento::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Documento $documento = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $documentoRimosso = false;

    #[ORM\ManyToOne(targetEntity: MembroFamiglia::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MembroFamiglia $membro = null;

    #[ORM\ManyToOne(targetEntity: Categoria::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Categoria $categoria = null;

    #[ORM\Column(length: 20, enumType: ScadenzaOrigine::class)]
    private ScadenzaOrigine $origine = ScadenzaOrigine::MANUALE;

    #[ORM\Column(length: 20, enumType: ScadenzaStato::class)]
    private ScadenzaStato $stato = ScadenzaStato::APERTA;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completataIl = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $completataDa = null;

    /** Richiesta di un ente da cui nasce (origine RICHIESTA): segue lo stato della richiesta. */
    #[ORM\ManyToOne(targetEntity: RichiestaDestinatario::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?RichiestaDestinatario $richiesta = null;

    /**
     * Scadenza creata da una richiesta con "entro il": non si spunta a mano;
     * va "in verifica" quando la famiglia risponde, si chiude quando l'ente
     * accetta, si riapre se l'ente chiede di rifirmare.
     */
    public static function perRichiesta(RichiestaDestinatario $destinatario, User $owner, string $titolo, \DateTimeImmutable $data, ?MembroFamiglia $membro): self
    {
        $s = new self($destinatario->getFamiglia(), $owner, mb_substr($titolo, 0, 200), $data);
        $s->update($s->titolo, null, $data, ScadenzaRicorrenza::NESSUNA, 3, null, $membro, null);
        $s->origine = ScadenzaOrigine::RICHIESTA;
        $s->richiesta = $destinatario;

        return $s;
    }

    public function getRichiesta(): ?RichiestaDestinatario
    {
        return $this->richiesta;
    }

    public function segnaInVerifica(): void
    {
        $this->stato = ScadenzaStato::IN_VERIFICA;
    }

    public function riapri(): void
    {
        $this->stato = ScadenzaStato::APERTA;
        $this->completataIl = null;
        $this->completataDa = null;
    }

    public function chiudi(): void
    {
        $this->stato = ScadenzaStato::FATTA;
        $this->completataIl = new \DateTimeImmutable();
    }

    public function __construct(Tenant $tenant, User $owner, string $titolo, \DateTimeImmutable $data)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->titolo = $titolo;
        $this->data = $data;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitolo(): string
    {
        return $this->titolo;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getData(): \DateTimeImmutable
    {
        return $this->data;
    }

    public function getRicorrenza(): ScadenzaRicorrenza
    {
        return $this->ricorrenza;
    }

    public function getPromemoriaGiorni(): ?int
    {
        return $this->promemoriaGiorni;
    }

    public function getPromemoriaIl(): ?\DateTimeImmutable
    {
        return $this->promemoriaIl;
    }

    public function getPromemoriaInviatoIl(): ?\DateTimeImmutable
    {
        return $this->promemoriaInviatoIl;
    }

    public function getDocumento(): ?Documento
    {
        return $this->documento;
    }

    public function isDocumentoRimosso(): bool
    {
        return $this->documentoRimosso;
    }

    public function getMembro(): ?MembroFamiglia
    {
        return $this->membro;
    }

    public function getCategoria(): ?Categoria
    {
        return $this->categoria;
    }

    public function getOrigine(): ScadenzaOrigine
    {
        return $this->origine;
    }

    public function getStato(): ScadenzaStato
    {
        return $this->stato;
    }

    public function getCompletataIl(): ?\DateTimeImmutable
    {
        return $this->completataIl;
    }

    public function getCompletataDa(): ?User
    {
        return $this->completataDa;
    }

    /** Modifica dei dati; se cambiano data o anticipo il promemoria si riprogramma. */
    public function update(string $titolo, ?string $note, \DateTimeImmutable $data, ScadenzaRicorrenza $ricorrenza, ?int $promemoriaGiorni, ?Documento $documento, ?MembroFamiglia $membro, ?Categoria $categoria): void
    {
        $this->titolo = $titolo;
        $this->note = null !== $note && '' !== trim($note) ? $note : null;
        $this->ricorrenza = $ricorrenza;
        if ($documento !== $this->documento) {
            $this->documentoRimosso = false;
        }
        $this->documento = $documento;
        $this->membro = $membro;
        $this->categoria = $categoria;
        if ($data != $this->data || $promemoriaGiorni !== $this->promemoriaGiorni || null === $this->promemoriaIl && null !== $promemoriaGiorni) {
            $this->data = $data;
            $this->promemoriaGiorni = $promemoriaGiorni;
            $this->promemoriaIl = null !== $promemoriaGiorni ? $data->modify(sprintf('-%d days', $promemoriaGiorni)) : null;
            $this->promemoriaInviatoIl = null;
        }
    }

    /**
     * Segna come fatta. Se ricorrente restituisce la scadenza successiva (da salvare),
     * con gli stessi dati e lo stesso proprietario.
     */
    public function complete(User $by): ?self
    {
        $this->stato = ScadenzaStato::FATTA;
        $this->completataIl = new \DateTimeImmutable();
        $this->completataDa = $by;

        $nextDate = $this->ricorrenza->next($this->data);
        if (null === $nextDate) {
            return null;
        }
        $next = new self($this->tenant, $this->owner, $this->titolo, $nextDate);
        $next->update($this->titolo, $this->note, $nextDate, $this->ricorrenza, $this->promemoriaGiorni, $this->documentoRimosso ? null : $this->documento, $this->membro, $this->categoria);

        return $next;
    }

    public function markPromemoriaInviato(): void
    {
        $this->promemoriaInviatoIl = new \DateTimeImmutable();
    }

    public function isScaduta(?\DateTimeImmutable $oggi = null): bool
    {
        return ScadenzaStato::APERTA === $this->stato && $this->data < ($oggi ?? new \DateTimeImmutable('today'));
    }
}
