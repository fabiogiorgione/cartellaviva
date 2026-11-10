<?php

namespace App\Ente\Entity;

use App\Account\Entity\User;
use App\Ente\Enum\EnteStato;
use App\Ente\Enum\EnteTipo;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Dati e stato di verifica di un ente (tenant di tipo azienda).
 *
 * Flusso: registrazione -> codice all'email istituzionale (EMAIL_DA_CONFERMARE)
 * -> IN_VERIFICA -> il superadmin approva (VERIFICATO), chiede integrazioni o
 * respinge. Se cambiano codice o email istituzionale si torna a confermare.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ente_profilo')]
#[PermissionResource('ente', 'Ente', [
    'read' => 'Vede il profilo dell\'ente, anni, sedi e gruppi',
    'manage' => 'Gestisce profilo, anni, sedi, gruppi e operatori',
    'tutti_i_gruppi' => 'Vede e gestisce iscritti e richieste di tutti i gruppi (segreteria)',
], sensitive: false)]
class EnteProfilo
{
    public const CODE_TTL = '+30 minutes';
    public const CODE_MAX_ATTEMPTS = 5;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 20, enumType: EnteTipo::class)]
    private EnteTipo $tipo;

    #[ORM\Column(length: 16)]
    private string $codiceFiscale;

    /** Scuole: codice meccanografico (10 caratteri). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codiceMeccanografico = null;

    /** Associazioni: numero di iscrizione al RUNTS. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $numeroRunts = null;

    #[ORM\Column(length: 180)]
    private string $emailIstituzionale;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sedeLegale = null;

    #[ORM\Column(length: 30, enumType: EnteStato::class)]
    private EnteStato $stato = EnteStato::EMAIL_DA_CONFERMARE;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codiceHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $codiceScadenza = null;

    #[ORM\Column]
    private int $codiceTentativi = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailConfermataIl = null;

    /** Controlli del portale: {"registro": bool, "telefonata": bool}. */
    #[ORM\Column(type: Types::JSON)]
    private array $controlli = [];

    /** Nota del portale per l'ente (integrazioni o rifiuto). */
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $notaPortale = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $verificatoDa = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verificatoIl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, EnteTipo $tipo, string $codiceFiscale, string $emailIstituzionale)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->tipo = $tipo;
        $this->codiceFiscale = $codiceFiscale;
        $this->emailIstituzionale = $emailIstituzionale;
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

    public function getTipo(): EnteTipo
    {
        return $this->tipo;
    }

    public function getCodiceFiscale(): string
    {
        return $this->codiceFiscale;
    }

    public function getCodiceMeccanografico(): ?string
    {
        return $this->codiceMeccanografico;
    }

    public function getNumeroRunts(): ?string
    {
        return $this->numeroRunts;
    }

    public function getEmailIstituzionale(): string
    {
        return $this->emailIstituzionale;
    }

    public function getSedeLegale(): ?string
    {
        return $this->sedeLegale;
    }

    public function getStato(): EnteStato
    {
        return $this->stato;
    }

    public function isVerificato(): bool
    {
        return EnteStato::VERIFICATO === $this->stato;
    }

    public function getEmailConfermataIl(): ?\DateTimeImmutable
    {
        return $this->emailConfermataIl;
    }

    /** @return array<string, bool> */
    public function getControlli(): array
    {
        return $this->controlli;
    }

    public function getNotaPortale(): ?string
    {
        return $this->notaPortale;
    }

    public function getVerificatoIl(): ?\DateTimeImmutable
    {
        return $this->verificatoIl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Aggiorna i dati identificativi. Se cambiano codice o email l'ente torna a
     * confermare l'email (e quindi in verifica): i nuovi invii si sospendono.
     *
     * @return bool true se serve una nuova conferma
     */
    public function aggiornaDati(string $codiceFiscale, ?string $codiceMeccanografico, ?string $numeroRunts, string $emailIstituzionale, ?string $sedeLegale): bool
    {
        $chiaveCambiata = $codiceFiscale !== $this->codiceFiscale
            || $codiceMeccanografico !== $this->codiceMeccanografico
            || $numeroRunts !== $this->numeroRunts
            || $emailIstituzionale !== $this->emailIstituzionale;
        $this->codiceFiscale = $codiceFiscale;
        $this->codiceMeccanografico = $codiceMeccanografico;
        $this->numeroRunts = $numeroRunts;
        $this->emailIstituzionale = $emailIstituzionale;
        $this->sedeLegale = $sedeLegale;
        if ($chiaveCambiata) {
            $this->stato = EnteStato::EMAIL_DA_CONFERMARE;
            $this->emailConfermataIl = null;
            $this->controlli = [];
        }

        return $chiaveCambiata;
    }

    public function nuovoCodice(string $hash): void
    {
        $this->codiceHash = $hash;
        $this->codiceScadenza = new \DateTimeImmutable(self::CODE_TTL);
        $this->codiceTentativi = 0;
    }

    /**
     * @return 'ok'|'errato'|'scaduto'|'bloccato'
     */
    public function verificaCodice(string $hash): string
    {
        if (null === $this->codiceHash || null === $this->codiceScadenza || $this->codiceScadenza <= new \DateTimeImmutable()) {
            return 'scaduto';
        }
        if ($this->codiceTentativi >= self::CODE_MAX_ATTEMPTS) {
            return 'bloccato';
        }
        if (!hash_equals($this->codiceHash, $hash)) {
            ++$this->codiceTentativi;

            return 'errato';
        }
        $this->codiceHash = null;
        $this->codiceScadenza = null;
        $this->emailConfermataIl = new \DateTimeImmutable();
        // Dopo integrazioni o rifiuto la conferma rimanda comunque in verifica.
        $this->stato = EnteStato::IN_VERIFICA;

        return 'ok';
    }

    /** @param array<string, bool> $controlli */
    public function decidi(EnteStato $stato, User $da, ?string $nota, array $controlli): void
    {
        $this->stato = $stato;
        $this->notaPortale = $nota;
        $this->controlli = $controlli;
        $this->verificatoDa = $da;
        $this->verificatoIl = new \DateTimeImmutable();
    }
}
