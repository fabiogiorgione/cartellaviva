<?php

namespace App\Pratiche\Entity;

use App\Account\Entity\User;
use App\Pratiche\Enum\PraticaStato;
use App\Pratiche\Repository\PraticaRepository;
use App\Scadenze\Entity\Scadenza;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Shared\Entity\Contract\TenantOwnedTrait;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Pratica (fase 2): un modello (oggi il 730) per un anno, con le persone
 * della famiglia coinvolte e le voci raccolte dai documenti.
 *
 * - owner = chi l'ha avviata: con pratica:manage:own la gestisce solo lui;
 * - i valori delle voci sono cifrati con una chiave propria della pratica
 *   (DEK), a sua volta cifrata con la chiave del tenant, come i documenti;
 * - il consenso per i documenti cifrati si dà una volta per pratica e si
 *   registra (chi e quando);
 * - "non serve": requisiti che per una persona non servono (es. la CU di un
 *   figlio), così non compaiono in "Cosa manca".
 */
#[ORM\Entity(repositoryClass: PraticaRepository::class)]
#[ORM\Table(name: 'pratica')]
#[ORM\Index(name: 'idx_pratica_tenant_modello', columns: ['tenant_id', 'modello', 'anno'])]
#[ORM\HasLifecycleCallbacks]
#[PermissionResource('pratica', 'Pratiche (730, ISEE)', [
    'read' => 'Visualizza le pratiche (730, ISEE) e le voci raccolte dai documenti che può leggere',
    'manage:own' => 'Avvia e gestisce le proprie pratiche',
    'manage:all' => 'Gestisce tutte le pratiche del tenant',
], sensitive: false)]
class Pratica implements OwnedResourceInterface
{
    use TenantOwnedTrait;
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20)]
    private string $modello;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $anno;

    #[ORM\Column(length: 20, enumType: PraticaStato::class)]
    private PraticaStato $stato = PraticaStato::IN_CORSO;

    /** @var Collection<int, MembroFamiglia> */
    #[ORM\ManyToMany(targetEntity: MembroFamiglia::class)]
    #[ORM\JoinTable(name: 'pratica_membro_famiglia')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $persone;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $consensoCifratiDa = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consensoCifratiIl = null;

    public const ANALISI_SERVER = 'server';
    public const ANALISI_TELEFONO = 'telefono';

    /** Dove si analizzano i documenti: sul server (Ollama) o sull'iPhone (modello Apple). */
    #[ORM\Column(length: 10, options: ['default' => 'server'])]
    private string $analisiSu = self::ANALISI_SERVER;

    /** @var list<string> "<requisito>" o "<requisito>|<id persona>" */
    #[ORM\Column(type: Types::JSON)]
    private array $nonServe = [];

    /**
     * Dove cercare: null = tutti i documenti della famiglia. Altrimenti i documenti
     * di una delle categorie o con una delle etichette; con "tutti" ognuno di essi
     * va all'analisi, anche senza parole chiave e di qualsiasi anno.
     *
     * @var array{categorie: list<string>, etichette: list<string>, tutti: bool}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $ambito = null;

    /** Promemoria del rinnovo (es. ISEE al 31 dicembre), creato quando la pratica è inviata. */
    #[ORM\ManyToOne(targetEntity: Scadenza::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Scadenza $scadenzaRinnovo = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedDek;

    #[ORM\Column]
    private int $keyVersion;

    public function __construct(Tenant $tenant, User $owner, string $modello, int $anno, string $encryptedDek, int $keyVersion, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->modello = $modello;
        $this->anno = $anno;
        $this->encryptedDek = $encryptedDek;
        $this->keyVersion = $keyVersion;
        $this->persone = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getModello(): string
    {
        return $this->modello;
    }

    public function getAnno(): int
    {
        return $this->anno;
    }

    public function getStato(): PraticaStato
    {
        return $this->stato;
    }

    public function setStato(PraticaStato $stato): void
    {
        $this->stato = $stato;
    }

    /** @return list<MembroFamiglia> */
    public function getPersone(): array
    {
        return array_values($this->persone->toArray());
    }

    public function haPersona(MembroFamiglia $membro): bool
    {
        return $this->persone->contains($membro);
    }

    /** @param list<MembroFamiglia> $persone */
    public function replacePersone(array $persone): void
    {
        $this->persone->clear();
        foreach ($persone as $p) {
            $this->persone->add($p);
        }
    }

    public function getConsensoCifratiDa(): ?User
    {
        return $this->consensoCifratiDa;
    }

    public function getConsensoCifratiIl(): ?\DateTimeImmutable
    {
        return $this->consensoCifratiIl;
    }

    public function haConsensoCifrati(): bool
    {
        return null !== $this->consensoCifratiIl;
    }

    public function daiConsensoCifrati(User $chi): void
    {
        $this->consensoCifratiDa = $chi;
        $this->consensoCifratiIl = new \DateTimeImmutable();
    }

    public function revocaConsensoCifrati(): void
    {
        $this->consensoCifratiDa = null;
        $this->consensoCifratiIl = null;
    }

    /** @return list<string> */
    public function getNonServe(): array
    {
        return $this->nonServe;
    }

    public function nonServe(string $requisito, ?MembroFamiglia $persona): bool
    {
        return \in_array(self::chiaveNonServe($requisito, $persona), $this->nonServe, true);
    }

    public function segnaNonServe(string $requisito, ?MembroFamiglia $persona, bool $nonServe): void
    {
        $chiave = self::chiaveNonServe($requisito, $persona);
        $this->nonServe = array_values(array_filter($this->nonServe, static fn (string $k) => $k !== $chiave));
        if ($nonServe) {
            $this->nonServe[] = $chiave;
        }
    }

    private static function chiaveNonServe(string $requisito, ?MembroFamiglia $persona): string
    {
        return null === $persona ? $requisito : $requisito.'|'.$persona->getId()->toRfc4122();
    }

    /** @return array{categorie: list<string>, etichette: list<string>, tutti: bool}|null */
    public function getAmbito(): ?array
    {
        return $this->ambito;
    }

    /** @param array{categorie: list<string>, etichette: list<string>, tutti: bool}|null $ambito */
    public function setAmbito(?array $ambito): void
    {
        $this->ambito = $ambito;
    }

    /** Ogni documento dell'ambito va all'analisi (scelti a mano: niente filtri su parole e anni). */
    public function analizzaTutti(): bool
    {
        return true === ($this->ambito['tutti'] ?? false);
    }

    public function getAnalisiSu(): string
    {
        return $this->analisiSu;
    }

    public function analisiSulTelefono(): bool
    {
        return self::ANALISI_TELEFONO === $this->analisiSu;
    }

    public function setAnalisiSu(string $dove): void
    {
        $this->analisiSu = self::ANALISI_TELEFONO === $dove ? self::ANALISI_TELEFONO : self::ANALISI_SERVER;
    }

    public function getScadenzaRinnovo(): ?Scadenza
    {
        return $this->scadenzaRinnovo;
    }

    public function collegaScadenzaRinnovo(?Scadenza $scadenza): void
    {
        $this->scadenzaRinnovo = $scadenza;
    }

    public function getEncryptedDek(): string
    {
        return $this->encryptedDek;
    }

    public function getKeyVersion(): int
    {
        return $this->keyVersion;
    }

    public function rewrapDek(string $encryptedDek, int $keyVersion): void
    {
        $this->encryptedDek = $encryptedDek;
        $this->keyVersion = $keyVersion;
    }
}
