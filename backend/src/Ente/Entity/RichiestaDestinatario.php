<?php

namespace App\Ente\Entity;

use App\Ente\Repository\RichiestaDestinatarioRepository;
use App\Account\Entity\User;
use App\Documenti\Entity\Documento;
use App\Ente\Enum\DestinatarioStato;
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
 * La richiesta come la vede UNA famiglia, per UN iscritto. Una sola risposta
 * per famiglia: chi carica dichiara quali referenti di quella famiglia hanno
 * firmato. Se l'iscritto ha referenti in due famiglie (genitori separati) ci
 * sono due destinatari, e nessuna vede la risposta dell'altra.
 *
 * Per il voter il tenant è la FAMIGLIA (richiesta:read / richiesta:respond).
 */
#[ORM\Entity(repositoryClass: RichiestaDestinatarioRepository::class)]
#[ORM\Table(name: 'ente_richiesta_destinatario')]
#[ORM\Index(name: 'idx_richiesta_dest_famiglia', columns: ['famiglia_id', 'stato'])]
#[ORM\UniqueConstraint(name: 'uniq_richiesta_dest', columns: ['richiesta_id', 'iscritto_id', 'famiglia_id'])]
#[PermissionResource('richiesta', 'Richieste degli enti', [
    'read' => 'Vede le richieste degli enti collegati',
    'respond' => 'Risponde alle richieste (firma, invio, presa visione)',
], sensitive: false)]
class RichiestaDestinatario implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Richiesta::class, inversedBy: 'destinatari')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Richiesta $richiesta;

    #[ORM\ManyToOne(targetEntity: Iscritto::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Iscritto $iscritto;

    /**
     * Famiglia collegata; null per chi risponde dal link sicuro senza account,
     * e dopo la cancellazione definitiva della famiglia: le copie consegnate
     * (e i codici di firma) restano all'ente.
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'famiglia_id', nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $famiglia;

    /** Referente senza collegamento che risponde dal link (una sola richiesta, codice via email). */
    #[ORM\ManyToOne(targetEntity: Referente::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Referente $referenteLink = null;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $linkTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $linkScadenza = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $otpHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $otpScadenza = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $otpTentativi = 0;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sessioneHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sessioneScadenza = null;

    /** Validità del documento consegnato (es. certificato medico), se l'ente la chiede. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validoFino = null;

    /** Rinnovo automatico già inviato per questa consegna. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rinnovataIl = null;

    /** Copia dell'ente eliminata per scadenza della conservazione. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $fileRimossoIl = null;

    /** @var Collection<int, Referente> referenti dell'iscritto collegati a questa famiglia */
    #[ORM\ManyToMany(targetEntity: Referente::class)]
    #[ORM\JoinTable(name: 'ente_richiesta_destinatario_referente')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $referenti;

    #[ORM\Column(length: 20, enumType: DestinatarioStato::class)]
    private DestinatarioStato $stato = DestinatarioStato::INVIATA;

    /** Id dei referenti che la famiglia dichiara firmatari. */
    #[ORM\Column(type: Types::JSON)]
    private array $firmatari = [];

    /** Messaggio della famiglia ("non posso firmare", "non autorizzo"...). */
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $messaggioFamiglia = null;

    /** Motivo della richiesta di rifirma (lo vede la famiglia). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $motivoEnte = null;

    /** Copia consegnata, conservata dall'ente (cifrata con la sua chiave). */
    #[ORM\ManyToOne(targetEntity: FileCifrato::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FileCifrato $risposta = null;

    /** Copia della famiglia ("Condivisi con enti"). */
    #[ORM\ManyToOne(targetEntity: Documento::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Documento $documentoFamiglia = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $rispostaDa = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $vistaIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rispostaIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verificataIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sollecitataIl = null;

    /** Destinatario via link sicuro: un referente senza collegamento. */
    public static function viaLink(Richiesta $richiesta, Referente $referente): self
    {
        $d = new self($richiesta, $referente->getIscritto(), null, [$referente]);
        $d->referenteLink = $referente;

        return $d;
    }

    /** @param iterable<Referente> $referenti */
    public function __construct(Richiesta $richiesta, Iscritto $iscritto, ?Tenant $famiglia, iterable $referenti)
    {
        $this->id = Uuid::v7();
        $this->richiesta = $richiesta;
        $this->iscritto = $iscritto;
        $this->famiglia = $famiglia;
        $this->referenti = new ArrayCollection();
        foreach ($referenti as $r) {
            $this->referenti->add($r);
        }
        $richiesta->aggiungiDestinatario($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /** Per il voter: la famiglia; per i destinatari via link l'ente (nessuna famiglia può accedervi). */
    public function getTenant(): Tenant
    {
        return $this->famiglia ?? $this->richiesta->getTenant();
    }

    public function getFamiglia(): ?Tenant
    {
        return $this->famiglia;
    }

    public function getReferenteLink(): ?Referente
    {
        return $this->referenteLink;
    }

    public function isViaLink(): bool
    {
        return null === $this->famiglia && null !== $this->referenteLink;
    }

    // ---- Link sicuro ----------------------------------------------------------

    public function nuovoLink(string $tokenHash, \DateTimeImmutable $scadenza): void
    {
        $this->linkTokenHash = $tokenHash;
        $this->linkScadenza = $scadenza;
        $this->sessioneHash = null;
        $this->sessioneScadenza = null;
    }

    public function isLinkValido(): bool
    {
        return null !== $this->linkTokenHash && null !== $this->linkScadenza && $this->linkScadenza > new \DateTimeImmutable() && !$this->richiesta->isAnnullata();
    }

    public function getLinkScadenza(): ?\DateTimeImmutable
    {
        return $this->linkScadenza;
    }

    public function nuovoOtp(string $hash): void
    {
        $this->otpHash = $hash;
        $this->otpScadenza = new \DateTimeImmutable('+10 minutes');
        $this->otpTentativi = 0;
    }

    /** @return 'ok'|'errato'|'scaduto'|'bloccato' */
    public function verificaOtp(string $hash, string $sessioneHash): string
    {
        if (null === $this->otpHash || null === $this->otpScadenza || $this->otpScadenza <= new \DateTimeImmutable()) {
            return 'scaduto';
        }
        if ($this->otpTentativi >= 5) {
            return 'bloccato';
        }
        if (!hash_equals($this->otpHash, $hash)) {
            ++$this->otpTentativi;

            return 'errato';
        }
        $this->otpHash = null;
        $this->otpScadenza = null;
        $this->sessioneHash = $sessioneHash;
        $this->sessioneScadenza = new \DateTimeImmutable('+2 hours');

        return 'ok';
    }

    public function isSessioneValida(string $sessioneHash): bool
    {
        return null !== $this->sessioneHash && null !== $this->sessioneScadenza && $this->sessioneScadenza > new \DateTimeImmutable() && hash_equals($this->sessioneHash, $sessioneHash);
    }

    /** Il referente ha creato l'account: le sue risposte passano alla famiglia scelta. */
    public function passaAFamiglia(Tenant $famiglia, ?Documento $copia): void
    {
        $this->famiglia = $famiglia;
        $this->documentoFamiglia = $copia ?? $this->documentoFamiglia;
        $this->linkTokenHash = null;
        $this->linkScadenza = null;
        $this->sessioneHash = null;
        $this->sessioneScadenza = null;
    }

    // ---- Validità, rinnovo, conservazione --------------------------------------

    public function getValidoFino(): ?\DateTimeImmutable
    {
        return $this->validoFino;
    }

    public function setValidoFino(?\DateTimeImmutable $data): void
    {
        $this->validoFino = $data;
    }

    public function getRinnovataIl(): ?\DateTimeImmutable
    {
        return $this->rinnovataIl;
    }

    public function segnaRinnovata(): void
    {
        $this->rinnovataIl = new \DateTimeImmutable();
    }

    public function getFileRimossoIl(): ?\DateTimeImmutable
    {
        return $this->fileRimossoIl;
    }

    public function rimuoviFile(): void
    {
        $this->risposta = null;
        $this->fileRimossoIl = new \DateTimeImmutable();
    }

    public function getRichiesta(): Richiesta
    {
        return $this->richiesta;
    }

    public function getIscritto(): Iscritto
    {
        return $this->iscritto;
    }

    /** @return Collection<int, Referente> */
    public function getReferenti(): Collection
    {
        return $this->referenti;
    }

    public function getStato(): DestinatarioStato
    {
        return $this->stato;
    }

    /** @return list<string> */
    public function getFirmatari(): array
    {
        return $this->firmatari;
    }

    public function getMessaggioFamiglia(): ?string
    {
        return $this->messaggioFamiglia;
    }

    public function getMotivoEnte(): ?string
    {
        return $this->motivoEnte;
    }

    public function getRisposta(): ?FileCifrato
    {
        return $this->risposta;
    }

    public function getDocumentoFamiglia(): ?Documento
    {
        return $this->documentoFamiglia;
    }

    public function getRispostaDa(): ?User
    {
        return $this->rispostaDa;
    }

    public function getVistaIl(): ?\DateTimeImmutable
    {
        return $this->vistaIl;
    }

    public function getRispostaIl(): ?\DateTimeImmutable
    {
        return $this->rispostaIl;
    }

    public function getVerificataIl(): ?\DateTimeImmutable
    {
        return $this->verificataIl;
    }

    public function getSollecitataIl(): ?\DateTimeImmutable
    {
        return $this->sollecitataIl;
    }

    public function isScaduta(?\DateTimeImmutable $oggi = null): bool
    {
        $entro = $this->richiesta->getEntroIl();

        return null !== $entro && $this->stato->isDaFare() && $entro < ($oggi ?? new \DateTimeImmutable('today'));
    }

    public function segnaVista(): void
    {
        if (DestinatarioStato::INVIATA === $this->stato) {
            $this->stato = DestinatarioStato::VISTA;
        }
        $this->vistaIl ??= new \DateTimeImmutable();
    }

    /**
     * @param list<string> $firmatari
     */
    public function rispondi(?User $da, array $firmatari, ?FileCifrato $copiaEnte, ?Documento $copiaFamiglia, bool $daVerificare): void
    {
        $this->firmatari = $firmatari;
        $this->risposta = $copiaEnte;
        $this->documentoFamiglia = $copiaFamiglia;
        $this->rispostaDa = $da;
        $this->rispostaIl = new \DateTimeImmutable();
        $this->messaggioFamiglia = null;
        $this->motivoEnte = null;
        $this->vistaIl ??= $this->rispostaIl;
        $this->stato = $daVerificare ? DestinatarioStato::DA_VERIFICARE : DestinatarioStato::COMPLETATA;
    }

    public function rifiuta(?User $da, string $messaggio): void
    {
        $this->messaggioFamiglia = $messaggio;
        $this->rispostaDa = $da;
        $this->rispostaIl = new \DateTimeImmutable();
        $this->stato = DestinatarioStato::RIFIUTATA;
    }

    public function accetta(): void
    {
        $this->stato = DestinatarioStato::COMPLETATA;
        $this->verificataIl = new \DateTimeImmutable();
    }

    public function chiediRifirma(string $motivo): void
    {
        $this->stato = DestinatarioStato::DA_RIFIRMARE;
        $this->motivoEnte = $motivo;
        $this->verificataIl = new \DateTimeImmutable();
    }

    public function annulla(): void
    {
        if (!$this->stato->isConclusa()) {
            $this->stato = DestinatarioStato::ANNULLATA;
        }
    }

    public function segnaSollecitata(): void
    {
        $this->sollecitataIl = new \DateTimeImmutable();
    }
}
