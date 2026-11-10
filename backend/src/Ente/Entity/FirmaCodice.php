<?php

namespace App\Ente\Entity;

use App\Account\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Firma elettronica semplice (FES) "con codice" di UN referente su un documento
 * dell'ente: lettura del PDF, dichiarazione esplicita e codice monouso inviato
 * all'email del referente. Le evidenze (impronta del documento, data, canale,
 * IP) restano qui e nella pagina di attestazione aggiunta al PDF firmato.
 *
 * Valore: firma elettronica ai sensi dell'art. 20 c. 1-bis CAD, liberamente
 * valutabile in giudizio (non è una firma avanzata né qualificata).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ente_firma_codice')]
#[ORM\UniqueConstraint(name: 'uniq_firma_codice_verifica', columns: ['codice_verifica'])]
#[ORM\Index(name: 'idx_firma_codice_dest', columns: ['destinatario_id', 'referente_id'])]
class FirmaCodice
{
    public const DICHIARAZIONE = 'Dichiaro di aver letto il documento e di sottoscriverlo con questa firma elettronica.';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: RichiestaDestinatario::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RichiestaDestinatario $destinatario;

    #[ORM\ManyToOne(targetEntity: Referente::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Referente $referente;

    /** Account con cui ha firmato (null dal link senza account). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    /** Dati del firmatario al momento della firma (restano anche se poi cambiano). */
    #[ORM\Column(length: 200)]
    private string $nomeFirmatario;

    #[ORM\Column(length: 180)]
    private string $emailFirmatario;

    /** SHA-256 del documento originale dell'ente, mostrato e firmato. */
    #[ORM\Column(length: 64)]
    private string $documentoSha256;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $otpHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $otpScadenza = null;

    #[ORM\Column]
    private int $otpTentativi = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firmataIl = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 255)]
    private string $dichiarazione = self::DICHIARAZIONE;

    /** Codice pubblico per verificare l'attestazione (stampato sul PDF). */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $codiceVerifica = null;

    /** SHA-256 del PDF firmato finale (originale + attestazione). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $documentoFirmatoSha256 = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(RichiestaDestinatario $destinatario, Referente $referente, ?User $user, string $nomeFirmatario, string $emailFirmatario, string $documentoSha256)
    {
        $this->id = Uuid::v7();
        $this->destinatario = $destinatario;
        $this->referente = $referente;
        $this->user = $user;
        $this->nomeFirmatario = $nomeFirmatario;
        $this->emailFirmatario = $emailFirmatario;
        $this->documentoSha256 = $documentoSha256;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDestinatario(): RichiestaDestinatario
    {
        return $this->destinatario;
    }

    public function getReferente(): Referente
    {
        return $this->referente;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getNomeFirmatario(): string
    {
        return $this->nomeFirmatario;
    }

    public function getEmailFirmatario(): string
    {
        return $this->emailFirmatario;
    }

    public function getDocumentoSha256(): string
    {
        return $this->documentoSha256;
    }

    public function getFirmataIl(): ?\DateTimeImmutable
    {
        return $this->firmataIl;
    }

    public function isFirmata(): bool
    {
        return null !== $this->firmataIl;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getDichiarazione(): string
    {
        return $this->dichiarazione;
    }

    public function getCodiceVerifica(): ?string
    {
        return $this->codiceVerifica;
    }

    public function getDocumentoFirmatoSha256(): ?string
    {
        return $this->documentoFirmatoSha256;
    }

    public function nuovoOtp(string $hash, ?User $user): void
    {
        $this->otpHash = $hash;
        $this->otpScadenza = new \DateTimeImmutable('+10 minutes');
        $this->otpTentativi = 0;
        $this->user = $user;
    }

    /** @return 'ok'|'errato'|'scaduto'|'bloccato' */
    public function firma(string $otpHash, ?string $ip, ?string $userAgent, string $codiceVerifica): string
    {
        if (null === $this->otpHash || null === $this->otpScadenza || $this->otpScadenza <= new \DateTimeImmutable()) {
            return 'scaduto';
        }
        if ($this->otpTentativi >= 5) {
            return 'bloccato';
        }
        if (!hash_equals($this->otpHash, $otpHash)) {
            ++$this->otpTentativi;

            return 'errato';
        }
        $this->otpHash = null;
        $this->otpScadenza = null;
        $this->firmataIl = new \DateTimeImmutable();
        $this->ip = null !== $ip ? mb_substr($ip, 0, 45) : null;
        $this->userAgent = null !== $userAgent ? mb_substr($userAgent, 0, 255) : null;
        $this->codiceVerifica = $codiceVerifica;

        return 'ok';
    }

    public function setDocumentoFirmatoSha256(string $sha): void
    {
        $this->documentoFirmatoSha256 = $sha;
    }
}
