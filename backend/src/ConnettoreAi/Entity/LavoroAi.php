<?php

namespace App\ConnettoreAi\Entity;

use App\Documenti\Entity\Documento;
use App\Pratiche\Entity\Pratica;
use App\Tenancy\Entity\Tenant;
use App\ConnettoreAi\Repository\LavoroAiRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un'analisi da fare su un connettore. Niente testo né prompt: si costruiscono
 * all'assegnazione e vivono solo in memoria. Un lavoro concluso si cancella.
 *
 * Tipi: le voci di una pratica (pratica_voci, l'unico con la pratica), i dati
 * dell'indice di un documento (indice_dati: emittente, data, importo, tag…), la
 * classificazione automatica di un documento importato (classificazione) e la
 * proposta di categoria del wizard (proposta_categoria).
 *
 * Le classificazioni sono catene di domande al modello: in "dati" restano solo
 * i risultati già interpretati (nomi di categoria, sì/no, la proposta finale),
 * mai testo, prompt o risposte grezze; la riga si cancella alla fine.
 */
#[ORM\Entity(repositoryClass: LavoroAiRepository::class)]
#[ORM\Table(name: 'lavoro_ai')]
#[ORM\UniqueConstraint(name: 'uniq_lavoro_ai', columns: ['tipo', 'pratica_id', 'documento_id'])]
// Senza pratica (NULL) il vincolo qui sopra non vale: un solo lavoro per tipo e documento.
#[ORM\UniqueConstraint(name: 'uniq_lavoro_ai_documento', columns: ['tipo', 'documento_id'], options: ['where' => '(pratica_id IS NULL)'])]
#[ORM\Index(name: 'idx_lavoro_ai_tenant_stato', columns: ['tenant_id', 'stato'])]
class LavoroAi
{
    public const PRATICA_VOCI = 'pratica_voci';
    public const INDICE_DATI = 'indice_dati';
    public const CLASSIFICAZIONE = 'classificazione';
    public const PROPOSTA_CATEGORIA = 'proposta_categoria';
    public const IN_ATTESA = 'in_attesa';
    public const ASSEGNATO = 'assegnato';
    /** Proposta di categoria finita: aspetta che il wizard la ritiri. */
    public const PRONTO = 'pronto';
    /** Proposta di categoria che il Mac non ha fatto: la fa il server alla prossima richiesta del wizard. */
    public const AL_SERVER = 'al_server';
    public const LEASE_SECONDI = 300;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 20)]
    private string $tipo;

    #[ORM\ManyToOne(targetEntity: Pratica::class)]
    #[ORM\JoinColumn(name: 'pratica_id', nullable: true, onDelete: 'CASCADE')]
    private ?Pratica $pratica;

    #[ORM\ManyToOne(targetEntity: Documento::class)]
    #[ORM\JoinColumn(name: 'documento_id', nullable: false, onDelete: 'CASCADE')]
    private Documento $documento;

    #[ORM\Column(length: 10)]
    private string $stato = self::IN_ATTESA;

    #[ORM\ManyToOne(targetEntity: ConnettoreAi::class)]
    #[ORM\JoinColumn(name: 'connettore_id', nullable: true, onDelete: 'SET NULL')]
    private ?ConnettoreAi $connettore = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $assegnatoIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scadeIl = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $tentativi = 0;

    #[ORM\Column]
    private \DateTimeImmutable $creatoIl;

    /** @var array<string, mixed>|null stato di una catena di classificazione (vedi sopra) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dati = null;

    /** @param array<string, mixed>|null $dati */
    public function __construct(?Pratica $pratica, Documento $documento, string $tipo = self::PRATICA_VOCI, ?array $dati = null)
    {
        if ((null === $pratica) === (self::PRATICA_VOCI === $tipo)) {
            throw new \LogicException('Solo un lavoro di pratica ha la pratica.');
        }
        $this->id = Uuid::v7();
        $this->tenant = $documento->getTenant();
        $this->pratica = $pratica;
        $this->documento = $documento;
        $this->tipo = $tipo;
        $this->dati = $dati;
        $this->creatoIl = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    /** Null per un lavoro d'indice. */
    public function getPratica(): ?Pratica
    {
        return $this->pratica;
    }

    /** La pratica di un lavoro di pratica (gli altri tipi non ne hanno). */
    public function praticaDelLavoro(): Pratica
    {
        return $this->pratica ?? throw new \LogicException('Lavoro senza pratica.');
    }

    /** @return array<string, mixed> */
    public function getDati(): array
    {
        return $this->dati ?? [];
    }

    /** @param array<string, mixed> $dati */
    public function setDati(array $dati): void
    {
        $this->dati = $dati;
    }

    /**
     * Proposta finita: resta solo l'esito, finché il wizard lo ritira.
     *
     * @param array<string, mixed> $esito
     */
    public function pronto(array $esito): void
    {
        $this->stato = self::PRONTO;
        $this->connettore = null;
        $this->scadeIl = null;
        $this->dati = ['esito' => $esito];
    }

    /** Il Mac non l'ha fatta: la farà il server, alla prossima richiesta del wizard. */
    public function alServer(): void
    {
        $this->stato = self::AL_SERVER;
        $this->connettore = null;
        $this->scadeIl = null;
        $this->dati = null;
    }

    public function getDocumento(): Documento
    {
        return $this->documento;
    }

    public function getStato(): string
    {
        return $this->stato;
    }

    public function getConnettore(): ?ConnettoreAi
    {
        return $this->connettore;
    }

    public function getScadeIl(): ?\DateTimeImmutable
    {
        return $this->scadeIl;
    }

    public function getTentativi(): int
    {
        return $this->tentativi;
    }

    public function getCreatoIl(): \DateTimeImmutable
    {
        return $this->creatoIl;
    }

    public function assegna(ConnettoreAi $c, \DateTimeImmutable $ora): void
    {
        $this->stato = self::ASSEGNATO;
        $this->connettore = $c;
        $this->assegnatoIl = $ora;
        $this->scadeIl = $ora->modify('+'.self::LEASE_SECONDI.' seconds');
    }

    public function proroga(\DateTimeImmutable $ora): void
    {
        $this->scadeIl = $ora->modify('+'.self::LEASE_SECONDI.' seconds');
    }

    /** Del connettore e ancora in tempo? */
    public function diConnettore(ConnettoreAi $c, \DateTimeImmutable $ora): bool
    {
        return self::ASSEGNATO === $this->stato && $this->connettore === $c && null !== $this->scadeIl && $this->scadeIl > $ora;
    }

    /** Torna in attesa (lease scaduto, errore, connettore revocato). */
    public function rimetti(bool $tentativo = true): void
    {
        $this->stato = self::IN_ATTESA;
        $this->connettore = null;
        $this->assegnatoIl = null;
        $this->scadeIl = null;
        if ($tentativo) {
            ++$this->tentativi;
        }
    }
}
