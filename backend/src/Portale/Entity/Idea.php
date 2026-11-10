<?php

namespace App\Portale\Entity;

use App\Account\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * "Portale delle idee": proposte degli utenti per migliorare l'app, private
 * (le vede solo l'autore e il team del portale).
 *
 * Non è legata a una famiglia o a un ente: del contesto si registra solo il
 * tipo di spazio, la piattaforma e la versione dell'app. Se l'account viene
 * eliminato l'idea resta, anonima (autore SET NULL).
 *
 * I duplicati si uniscono a un'idea principale (duplicatoDi): l'autore di un
 * duplicato vede stato e risposta della principale.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idea')]
#[ORM\Index(name: 'idx_idea_stato', columns: ['stato', 'created_at'])]
#[ORM\Index(name: 'idx_idea_riepilogo', columns: ['riepilogo_inviato_il'])]
class Idea
{
    public const MIGLIORAMENTO = 'miglioramento';
    public const FUNZIONALITA = 'funzionalita';
    public const TIPI = [self::MIGLIORAMENTO, self::FUNZIONALITA];

    public const AREE = ['documenti', 'scadenze', 'scansione', 'import', 'enti', 'altro'];
    public const SPAZI = ['famiglia', 'ente', 'azienda'];
    public const PIATTAFORME = ['web', 'android', 'ios', 'windows', 'macos', 'linux'];

    public const NUOVA = 'nuova';
    public const LETTA = 'letta';
    public const IN_VALUTAZIONE = 'in_valutazione';
    public const PIANIFICATA = 'pianificata';
    public const REALIZZATA = 'realizzata';
    public const NON_PREVISTA = 'non_prevista';
    public const STATI = [self::NUOVA, self::LETTA, self::IN_VALUTAZIONE, self::PIANIFICATA, self::REALIZZATA, self::NON_PREVISTA];

    public const TESTO_MIN = 20;
    public const TESTO_MAX = 2000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autore;

    #[ORM\Column(length: 20)]
    private string $tipo;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $area;

    #[ORM\Column(type: 'text')]
    private string $testo;

    /** L'autore accetta di essere contattato: solo allora il supporto vede nome ed email. */
    #[ORM\Column]
    private bool $contattabile;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $tipoSpazio;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $piattaforma;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $versioneApp;

    #[ORM\Column(length: 20)]
    private string $stato = self::NUOVA;

    /** Visibile all'autore (e agli autori dei duplicati). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rispostaPubblica = null;

    /** Solo per il team del portale. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notaInterna = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Idea $duplicatoDi = null;

    /** Inclusa nel riepilogo giornaliero all'amministratore. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $riepilogoInviatoIl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $aggiornataIl;

    public function __construct(User $autore, string $tipo, ?string $area, string $testo, bool $contattabile, ?string $tipoSpazio, ?string $piattaforma, ?string $versioneApp)
    {
        $this->id = Uuid::v7();
        $this->autore = $autore;
        $this->tipo = $tipo;
        $this->area = $area;
        $this->testo = $testo;
        $this->contattabile = $contattabile;
        $this->tipoSpazio = $tipoSpazio;
        $this->piattaforma = $piattaforma;
        $this->versioneApp = $versioneApp;
        $this->createdAt = new \DateTimeImmutable();
        $this->aggiornataIl = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAutore(): ?User
    {
        return $this->autore;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getArea(): ?string
    {
        return $this->area;
    }

    public function getTesto(): string
    {
        return $this->testo;
    }

    public function isContattabile(): bool
    {
        return $this->contattabile;
    }

    public function getTipoSpazio(): ?string
    {
        return $this->tipoSpazio;
    }

    public function getPiattaforma(): ?string
    {
        return $this->piattaforma;
    }

    public function getVersioneApp(): ?string
    {
        return $this->versioneApp;
    }

    public function getStato(): string
    {
        return $this->stato;
    }

    public function getRispostaPubblica(): ?string
    {
        return $this->rispostaPubblica;
    }

    public function getNotaInterna(): ?string
    {
        return $this->notaInterna;
    }

    public function getDuplicatoDi(): ?Idea
    {
        return $this->duplicatoDi;
    }

    /** L'idea che fa fede per stato e risposta: la principale, se è un duplicato. */
    public function getPrincipale(): Idea
    {
        return $this->duplicatoDi ?? $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAggiornataIl(): \DateTimeImmutable
    {
        return $this->aggiornataIl;
    }

    public function setStato(string $stato): void
    {
        if (!\in_array($stato, self::STATI, true)) {
            throw new \InvalidArgumentException('Stato non valido.');
        }
        $this->stato = $stato;
        $this->aggiornataIl = new \DateTimeImmutable();
    }

    public function setRispostaPubblica(?string $risposta): void
    {
        $this->rispostaPubblica = $risposta;
        $this->aggiornataIl = new \DateTimeImmutable();
    }

    public function setNotaInterna(?string $nota): void
    {
        $this->notaInterna = $nota;
    }

    public function unisciA(?Idea $principale): void
    {
        $this->duplicatoDi = $principale;
        $this->aggiornataIl = new \DateTimeImmutable();
    }

    public function segnaRiepilogo(\DateTimeImmutable $quando): void
    {
        $this->riepilogoInviatoIl = $quando;
    }
}
