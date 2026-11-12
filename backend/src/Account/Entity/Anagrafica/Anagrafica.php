<?php

namespace App\Account\Entity\Anagrafica;

use App\Account\Repository\AnagraficaRepository;
use App\Account\Validator\ValidAnagrafica;
use App\Shared\Entity\Contract\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dati anagrafici di una persona fisica ("privato") o giuridica ("azienda").
 * Le regole di validazione sono in ValidAnagraficaValidator.
 *
 * Nazioni e comuni sono salvati con il codice ISTAT dello stato
 * (GeoCountry, Italia = 100) e indice comune (GeoComune).
 */
#[ORM\Entity(repositoryClass: AnagraficaRepository::class)]
#[ORM\Table(name: 'anagrafica')]
#[ORM\Index(name: 'idx_anagrafica_codice_fiscale', columns: ['codice_fiscale'])]
#[ORM\Index(name: 'idx_anagrafica_vat', columns: ['vat'])]
#[ORM\HasLifecycleCallbacks]
#[ValidAnagrafica(groups: [self::TYPEPRIVATO, self::TYPEAZIENDA, self::GROUP_MEMBRO_FAMIGLIA])]
class Anagrafica
{
    use TimestampableTrait;

    public const TYPEPRIVATO = 'privato';
    public const TYPEAZIENDA = 'azienda';

    /** Validazione ridotta per i membri della famiglia senza account (anche minori). */
    public const GROUP_MEMBRO_FAMIGLIA = 'membro_famiglia';

    public const STATOCIVILE_CONIUGATO = 'coniugato';
    public const STATOCIVILE_LIBERO = 'libero';
    public const STATOCIVILE_VEDOVO = 'vedovo';

    public const SESSO_MASCHIO = 'm';
    public const SESSO_FEMMINA = 'f';

    /** @var array<string, string> */
    public const TYPE_OPTIONS = [self::TYPEPRIVATO => 'Privato', self::TYPEAZIENDA => 'Azienda'];

    /** @var array<string, string> */
    public const SESSO_OPTIONS = [self::SESSO_MASCHIO => 'Maschio', self::SESSO_FEMMINA => 'Femmina'];

    /** @var array<string, string> */
    public const STATOCIVILE_OPTIONS = [
        self::STATOCIVILE_CONIUGATO => 'Coniugato',
        self::STATOCIVILE_LIBERO => 'Libero',
        self::STATOCIVILE_VEDOVO => 'Vedovo',
    ];

    /** Tipi di documento d'identità ammessi. */
    public const DOCUMENTO_IDENTITA_OPTIONS = [
        'tipo_documento_ci' => "Carta d'identità",
        'tipo_documento_ci_straniera' => "Carta d'identità straniera",
        'tipo_documento_pass' => 'Passaporto',
        'tipo_documento_pass_straniero' => 'Passaporto straniero',
        'tipo_documento_pa' => 'Patente',
        'tipo_documento_pds' => 'Permesso di soggiorno',
        'tipo_documento_vistoc' => 'Visto',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TYPEPRIVATO, self::TYPEAZIENDA], message: 'Il campo type, privato o azienda è obbligatorio', groups: ['Default', self::TYPEPRIVATO, self::TYPEAZIENDA])]
    private string $type;

    // ---- Persona fisica (per le aziende: legale rappresentante) ----------------

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $nome = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $cognome = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $soprannome = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $titolo = null;

    #[ORM\Column(length: 1, nullable: true)]
    #[Assert\Choice(choices: [self::SESSO_MASCHIO, self::SESSO_FEMMINA], message: 'Scegli maschio o femmina.')]
    private ?string $sesso = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: [self::STATOCIVILE_CONIUGATO, self::STATOCIVILE_LIBERO, self::STATOCIVILE_VEDOVO])]
    private ?string $statoCivile = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dataDiNascita = null;

    /** Codice ISTAT dello stato di nascita (Italia = 100). */
    #[ORM\Column(nullable: true)]
    private ?int $nazioneDiNascita = null;

    /** Indice comune (GeoComune) se nato in Italia. */
    #[ORM\Column(nullable: true)]
    private ?int $comuneDiNascita = null;

    /** Codice ISTAT dello stato di cittadinanza. */
    #[ORM\Column(nullable: true)]
    private ?int $cittadinanza = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $codiceFiscale = null;

    /** Codice fiscale inserito a mano (es. omocodia non ricostruibile): si controlla solo il formato. */
    #[ORM\Column(nullable: true)]
    private ?bool $codiceFiscaleManuale = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inItaliaDal = null;

    // ---- Documento di identità -------------------------------------------------

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Choice(callback: [self::class, 'documentoIdentitaKeys'])]
    private ?string $tipoDocumentoIdentita = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $numeroDocumentoIdentita = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $rilasciatoDaDocumentoIdentita = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dataRilascioDocumentoIdentita = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dataScadenzaDocumentoIdentita = null;

    // ---- Persona giuridica ------------------------------------------------------

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $denominazione = null;

    /** Partita IVA. */
    #[ORM\Column(length: 11, nullable: true)]
    private ?string $vat = null;

    /** Codice destinatario SDI per la fatturazione elettronica. */
    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: '/^[A-Z0-9]{6,7}$/', message: 'Il codice SDI deve avere 6 o 7 caratteri alfanumerici')]
    private ?string $sdi = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $capitaleSociale = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 255)]
    private ?string $sitoweb = null;

    // ---- Recapiti -----------------------------------------------------------------

    /** @var Collection<int, Address> */
    #[ORM\OneToMany(targetEntity: Address::class, mappedBy: 'anagrafica', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $addresses;

    /** @var Collection<int, Telefono> */
    #[ORM\OneToMany(targetEntity: Telefono::class, mappedBy: 'anagrafica', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $telefono;

    /** @var Collection<int, Email> */
    #[ORM\ManyToMany(targetEntity: Email::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinTable(name: 'anagrafica_emails')]
    #[ORM\JoinColumn(name: 'anagrafica_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'email_id', unique: true, onDelete: 'CASCADE')]
    #[Assert\Valid]
    private Collection $email;

    /** @var Collection<int, Email> */
    #[ORM\ManyToMany(targetEntity: Email::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinTable(name: 'anagrafica_pec')]
    #[ORM\JoinColumn(name: 'anagrafica_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'pec_id', unique: true, onDelete: 'CASCADE')]
    #[Assert\Valid]
    private Collection $pec;

    public function __construct(string $type = self::TYPEPRIVATO)
    {
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->addresses = new ArrayCollection();
        $this->telefono = new ArrayCollection();
        $this->email = new ArrayCollection();
        $this->pec = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** @return list<string> */
    public static function documentoIdentitaKeys(): array
    {
        return array_keys(self::DOCUMENTO_IDENTITA_OPTIONS);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function isAzienda(): bool
    {
        return self::TYPEAZIENDA === $this->type;
    }

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function setNome(?string $nome): void
    {
        $this->nome = $nome;
    }

    public function getCognome(): ?string
    {
        return $this->cognome;
    }

    public function setCognome(?string $cognome): void
    {
        $this->cognome = $cognome;
    }

    public function getFullName(bool $cognomeFirst = true): string
    {
        return trim($cognomeFirst ? $this->cognome.' '.$this->nome : $this->nome.' '.$this->cognome);
    }

    /** Per le aziende nome e cognome sono quelli del legale rappresentante. */
    public function getLegaleRappresentante(): string
    {
        return $this->getFullName();
    }

    public function getDisplayName(): string
    {
        return $this->isAzienda() && $this->denominazione ? $this->denominazione : $this->getFullName(false);
    }

    public function getSoprannome(): ?string
    {
        return $this->soprannome;
    }

    public function setSoprannome(?string $soprannome): void
    {
        $this->soprannome = $soprannome;
    }

    public function getTitolo(): ?string
    {
        return $this->titolo;
    }

    public function setTitolo(?string $titolo): void
    {
        $this->titolo = $titolo;
    }

    public function getSesso(): ?string
    {
        return $this->sesso;
    }

    public function setSesso(?string $sesso): void
    {
        $this->sesso = $sesso;
    }

    public function getStatoCivile(): ?string
    {
        return $this->statoCivile;
    }

    public function setStatoCivile(?string $statoCivile): void
    {
        $this->statoCivile = $statoCivile;
    }

    public function getDataDiNascita(): ?\DateTimeImmutable
    {
        return $this->dataDiNascita;
    }

    public function setDataDiNascita(?\DateTimeImmutable $dataDiNascita): void
    {
        $this->dataDiNascita = $dataDiNascita;
    }

    public function getNazioneDiNascita(): ?int
    {
        return $this->nazioneDiNascita;
    }

    public function setNazioneDiNascita(?int $nazioneDiNascita): void
    {
        $this->nazioneDiNascita = $nazioneDiNascita;
    }

    public function getComuneDiNascita(): ?int
    {
        return $this->comuneDiNascita;
    }

    public function setComuneDiNascita(?int $comuneDiNascita): void
    {
        $this->comuneDiNascita = $comuneDiNascita;
    }

    public function getCittadinanza(): ?int
    {
        return $this->cittadinanza;
    }

    public function setCittadinanza(?int $cittadinanza): void
    {
        $this->cittadinanza = $cittadinanza;
    }

    public function getCodiceFiscale(): ?string
    {
        return $this->codiceFiscale;
    }

    public function setCodiceFiscale(?string $codiceFiscale): void
    {
        $codiceFiscale = null !== $codiceFiscale ? strtoupper(str_replace(' ', '', $codiceFiscale)) : null;
        $this->codiceFiscale = '' === $codiceFiscale ? null : $codiceFiscale;
    }

    public function isCodiceFiscaleManuale(): ?bool
    {
        return $this->codiceFiscaleManuale;
    }

    public function setCodiceFiscaleManuale(?bool $codiceFiscaleManuale): void
    {
        $this->codiceFiscaleManuale = $codiceFiscaleManuale;
    }

    public function getInItaliaDal(): ?\DateTimeImmutable
    {
        return $this->inItaliaDal;
    }

    public function setInItaliaDal(?\DateTimeImmutable $inItaliaDal): void
    {
        $this->inItaliaDal = $inItaliaDal;
    }

    public function getTipoDocumentoIdentita(): ?string
    {
        return $this->tipoDocumentoIdentita;
    }

    public function setTipoDocumentoIdentita(?string $tipoDocumentoIdentita): void
    {
        $this->tipoDocumentoIdentita = $tipoDocumentoIdentita ?: null;
    }

    public function getNumeroDocumentoIdentita(): ?string
    {
        return $this->numeroDocumentoIdentita;
    }

    public function setNumeroDocumentoIdentita(?string $numeroDocumentoIdentita): void
    {
        $this->numeroDocumentoIdentita = $numeroDocumentoIdentita;
    }

    public function getRilasciatoDaDocumentoIdentita(): ?string
    {
        return $this->rilasciatoDaDocumentoIdentita;
    }

    public function setRilasciatoDaDocumentoIdentita(?string $rilasciatoDaDocumentoIdentita): void
    {
        $this->rilasciatoDaDocumentoIdentita = $rilasciatoDaDocumentoIdentita;
    }

    public function getDataRilascioDocumentoIdentita(): ?\DateTimeImmutable
    {
        return $this->dataRilascioDocumentoIdentita;
    }

    public function setDataRilascioDocumentoIdentita(?\DateTimeImmutable $data): void
    {
        $this->dataRilascioDocumentoIdentita = $data;
    }

    public function getDataScadenzaDocumentoIdentita(): ?\DateTimeImmutable
    {
        return $this->dataScadenzaDocumentoIdentita;
    }

    public function setDataScadenzaDocumentoIdentita(?\DateTimeImmutable $data): void
    {
        $this->dataScadenzaDocumentoIdentita = $data;
    }

    public function getDenominazione(): ?string
    {
        return $this->denominazione;
    }

    public function setDenominazione(?string $denominazione): void
    {
        $this->denominazione = $denominazione;
    }

    public function getVat(): ?string
    {
        return $this->vat;
    }

    public function setVat(?string $vat): void
    {
        $vat = null !== $vat ? preg_replace('/\s+/', '', $vat) : null;
        $this->vat = '' === $vat ? null : $vat;
    }

    public function getSdi(): ?string
    {
        return $this->sdi;
    }

    public function setSdi(?string $sdi): void
    {
        $this->sdi = $sdi ? strtoupper(trim($sdi)) : null;
    }

    public function getCapitaleSociale(): ?string
    {
        return $this->capitaleSociale;
    }

    public function setCapitaleSociale(?string $capitaleSociale): void
    {
        $this->capitaleSociale = $capitaleSociale;
    }

    public function getSitoweb(): ?string
    {
        return $this->sitoweb;
    }

    public function setSitoweb(?string $sitoweb): void
    {
        $this->sitoweb = $sitoweb ?: null;
    }

    /** @return Collection<int, Address> */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    public function addAddress(Address $address): void
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses->add($address);
            $address->setAnagrafica($this);
        }
    }

    public function removeAddress(Address $address): void
    {
        $this->addresses->removeElement($address);
    }

    /** Una sola residenza per anagrafica (l'ultima vince). */
    public function getResidenza(): ?Address
    {
        $found = $this->addresses->filter(static fn (Address $a) => Address::TYPE_RESIDENZA === $a->getType())->last();

        return $found ?: null;
    }

    public function getDomicilio(): ?Address
    {
        $found = $this->addresses->filter(static fn (Address $a) => Address::TYPE_DOMICILIO === $a->getType())->last();

        return $found ?: null;
    }

    /** @return Collection<int, Telefono> */
    public function getTelefono(): Collection
    {
        return $this->telefono;
    }

    public function addTelefono(Telefono $telefono): void
    {
        if (!$this->telefono->contains($telefono)) {
            $this->telefono->add($telefono);
            $telefono->setAnagrafica($this);
        }
    }

    public function removeTelefono(Telefono $telefono): void
    {
        $this->telefono->removeElement($telefono);
    }

    public function getTelefonoMainOrLast(): ?Telefono
    {
        foreach ($this->telefono as $t) {
            if ($t->isMain()) {
                return $t;
            }
        }

        return $this->telefono->last() ?: null;
    }

    /** @return Collection<int, Email> */
    public function getEmail(): Collection
    {
        return $this->email;
    }

    public function addEmail(Email $email): void
    {
        if (!$this->email->contains($email)) {
            $this->email->add($email);
        }
    }

    public function removeEmail(Email $email): void
    {
        $this->email->removeElement($email);
    }

    /** @return Collection<int, Email> */
    public function getPec(): Collection
    {
        return $this->pec;
    }

    public function addPec(Email $pec): void
    {
        if (!$this->pec->contains($pec)) {
            $this->pec->add($pec);
        }
    }

    public function removePec(Email $pec): void
    {
        $this->pec->removeElement($pec);
    }
}
