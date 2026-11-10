<?php

namespace App\Ente\Entity;

use App\Account\Entity\User;
use App\Ente\Enum\RichiestaTipo;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Entity\Tenant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Modello riusabile di richiesta ("Modelli e regole"):
 * - contenuto: tipo, titolo, istruzioni, PDF, chi firma;
 * - validità: si chiede a chi consegna fino a quando vale il documento
 *   (es. certificato medico), con rinnovo automatico N giorni prima;
 * - ricorrenza annuale (es. ogni 15 settembre a tutto l'ente);
 * - conservazione: le copie ricevute si eliminano N mesi dopo la conclusione.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ente_modello')]
class ModelloRichiesta implements TenantScopedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 120)]
    private string $nome;

    #[ORM\Column(length: 20, enumType: RichiestaTipo::class)]
    private RichiestaTipo $tipo;

    #[ORM\Column(length: 200)]
    private string $titolo;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $istruzioni = null;

    #[ORM\Column(length: 10)]
    private string $firmatari = Richiesta::FIRMATARI_TUTTI;

    #[ORM\ManyToOne(targetEntity: FileCifrato::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FileCifrato $allegato = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $chiediValidita = false;

    /** Per le firme: cartacea o con codice (FES). */
    #[ORM\Column(length: 10, options: ['default' => 'cartacea'])]
    private string $modalitaFirma = Richiesta::FIRMA_CARTACEA;

    public function getModalitaFirma(): string
    {
        return $this->modalitaFirma;
    }

    public function setModalitaFirma(string $modalita): void
    {
        $this->modalitaFirma = RichiestaTipo::FIRMA === $this->tipo && Richiesta::FIRMA_CODICE === $modalita ? Richiesta::FIRMA_CODICE : Richiesta::FIRMA_CARTACEA;
    }

    /** Giorni prima della scadenza del documento in cui ripartire con la richiesta (null = nessun rinnovo). */
    #[ORM\Column(nullable: true)]
    private ?int $rinnovoGiorni = null;

    /** Mesi di conservazione delle copie ricevute dopo la conclusione (null = senza limite). */
    #[ORM\Column(nullable: true)]
    private ?int $conservazioneMesi = null;

    /** Ricorrenza annuale: giorno e mese di invio a tutti gli iscritti dell'anno in corso. */
    #[ORM\Column(nullable: true)]
    private ?int $ricorrenzaGiorno = null;

    #[ORM\Column(nullable: true)]
    private ?int $ricorrenzaMese = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $prossimoInvio = null;

    /** Giorni concessi per rispondere (data "entro il" delle richieste automatiche). */
    #[ORM\Column(options: ['default' => 14])]
    private int $giorniRisposta = 14;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creatoDa;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, string $nome, RichiestaTipo $tipo, string $titolo, User $creatoDa)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->nome = $nome;
        $this->tipo = $tipo;
        $this->titolo = $titolo;
        $this->creatoDa = $creatoDa;
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

    public function getNome(): string
    {
        return $this->nome;
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

    public function getFirmatari(): string
    {
        return $this->firmatari;
    }

    public function getAllegato(): ?FileCifrato
    {
        return $this->allegato;
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

    public function getRicorrenzaGiorno(): ?int
    {
        return $this->ricorrenzaGiorno;
    }

    public function getRicorrenzaMese(): ?int
    {
        return $this->ricorrenzaMese;
    }

    public function getProssimoInvio(): ?\DateTimeImmutable
    {
        return $this->prossimoInvio;
    }

    public function getGiorniRisposta(): int
    {
        return $this->giorniRisposta;
    }

    public function getCreatoDa(): ?User
    {
        return $this->creatoDa;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function aggiorna(string $nome, RichiestaTipo $tipo, string $titolo, ?string $istruzioni, string $firmatari, bool $chiediValidita, ?int $rinnovoGiorni, ?int $conservazioneMesi, int $giorniRisposta): void
    {
        $this->nome = $nome;
        $this->tipo = $tipo;
        $this->titolo = $titolo;
        $this->istruzioni = $istruzioni;
        $this->firmatari = $firmatari;
        $this->chiediValidita = $chiediValidita || null !== $rinnovoGiorni;
        $this->rinnovoGiorni = $rinnovoGiorni;
        $this->conservazioneMesi = $conservazioneMesi;
        $this->giorniRisposta = $giorniRisposta;
    }

    public function setAllegato(?FileCifrato $allegato): void
    {
        $this->allegato = $allegato;
    }

    /** null = nessuna ricorrenza. Calcola il prossimo invio da oggi. */
    public function impostaRicorrenza(?int $giorno, ?int $mese, ?\DateTimeImmutable $oggi = null): void
    {
        $this->ricorrenzaGiorno = $giorno;
        $this->ricorrenzaMese = $mese;
        $this->prossimoInvio = null === $giorno || null === $mese ? null : self::prossimaData($giorno, $mese, $oggi ?? new \DateTimeImmutable('today'));
    }

    /** Dopo l'invio: la stessa data dell'anno successivo. */
    public function avanzaRicorrenza(\DateTimeImmutable $oggi): void
    {
        if (null !== $this->ricorrenzaGiorno && null !== $this->ricorrenzaMese) {
            $this->prossimoInvio = self::prossimaData($this->ricorrenzaGiorno, $this->ricorrenzaMese, $oggi->modify('+1 day'));
        }
    }

    /** Prima data >= $da con quel giorno e mese (il 29/2 diventa il 28/2 negli anni non bisestili). */
    public static function prossimaData(int $giorno, int $mese, \DateTimeImmutable $da): \DateTimeImmutable
    {
        foreach ([(int) $da->format('Y'), (int) $da->format('Y') + 1] as $anno) {
            $g = min($giorno, (int) (new \DateTimeImmutable(sprintf('%d-%02d-01', $anno, $mese)))->format('t'));
            $data = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $anno, $mese, $g));
            if ($data >= $da->setTime(0, 0)) {
                return $data;
            }
        }

        throw new \LogicException('Data di ricorrenza non calcolabile.');
    }
}
