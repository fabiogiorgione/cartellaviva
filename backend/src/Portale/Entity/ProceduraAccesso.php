<?php

namespace App\Portale\Entity;

use App\Account\Entity\User;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Procedure del supporto che sbloccano un accesso, sempre con verifica
 * dell'identità e 72 ore di attesa annullabili:
 *
 * - RECUPERO_2FA: l'utente ha perso telefono e codici. Richiesta dall'app ->
 *   conferma dal link email -> il supporto verifica l'identità -> 72 ore con
 *   avviso a lui e ai proprietari delle sue famiglie -> 2FA disattivata,
 *   sessioni revocate, nuova 2FA obbligatoria.
 * - NUOVO_PROPRIETARIO: famiglia senza proprietari attivi. Il supporto verifica
 *   l'identità di un membro esistente -> 72 ore con avviso a tutti i membri ->
 *   il membro diventa proprietario.
 *
 * Del documento d'identità si annota solo l'esito (tipo e ultime cifre): non
 * viene caricato né conservato.
 */
#[ORM\Entity]
#[ORM\Table(name: 'procedura_accesso')]
#[ORM\Index(name: 'idx_procedura_stato', columns: ['stato', 'eseguibile_il'])]
#[ORM\UniqueConstraint(name: 'uniq_procedura_conferma', columns: ['token_conferma_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_procedura_annulla', columns: ['token_annulla_hash'])]
class ProceduraAccesso
{
    public const RECUPERO_2FA = 'recupero_2fa';
    public const NUOVO_PROPRIETARIO = 'nuovo_proprietario';

    public const EMAIL_DA_CONFERMARE = 'email_da_confermare';
    public const IDENTITA_DA_VERIFICARE = 'identita_da_verificare';
    public const IN_ATTESA = 'in_attesa';
    public const COMPLETATA = 'completata';
    public const RESPINTA = 'respinta';
    public const ANNULLATA = 'annullata';

    public const ATTESA = '+72 hours';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 30)]
    private string $tipo;

    /** Utente da sbloccare (recupero) o membro da promuovere (nuovo proprietario). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 30)]
    private string $stato;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $motivo;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenConfermaHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tokenConfermaScadenza = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenAnnullaHash = null;

    /** Esito della verifica: tipo di documento e ultime cifre, mai l'immagine. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $verificaDocumento = null;

    /** Come è stata verificata l'identità (videochiamata, di persona...). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $verificaCome = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $verificataDa = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $nota = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $eseguibileIl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $chiusaIl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(string $tipo, User $user, string $stato, ?string $motivo)
    {
        $this->id = Uuid::v7();
        $this->tipo = $tipo;
        $this->user = $user;
        $this->stato = $stato;
        $this->motivo = $motivo;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function recupero2fa(User $user, ?string $motivo, string $tokenConfermaHash): self
    {
        $p = new self(self::RECUPERO_2FA, $user, self::EMAIL_DA_CONFERMARE, $motivo);
        $p->tokenConfermaHash = $tokenConfermaHash;
        $p->tokenConfermaScadenza = new \DateTimeImmutable('+24 hours');

        return $p;
    }

    /** Il supporto ha già verificato l'identità del membro: si parte dall'attesa. */
    public static function nuovoProprietario(User $membro, Tenant $famiglia, User $supporto, string $documento, string $come, ?string $nota, string $tokenAnnullaHash): self
    {
        $p = new self(self::NUOVO_PROPRIETARIO, $membro, self::IDENTITA_DA_VERIFICARE, null);
        $p->tenant = $famiglia;
        $p->identitaVerificata($supporto, $documento, $come, $nota, $tokenAnnullaHash);

        return $p;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getStato(): string
    {
        return $this->stato;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getVerificaDocumento(): ?string
    {
        return $this->verificaDocumento;
    }

    public function getVerificaCome(): ?string
    {
        return $this->verificaCome;
    }

    public function getVerificataDa(): ?User
    {
        return $this->verificataDa;
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function getEseguibileIl(): ?\DateTimeImmutable
    {
        return $this->eseguibileIl;
    }

    public function getChiusaIl(): ?\DateTimeImmutable
    {
        return $this->chiusaIl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAperta(): bool
    {
        return \in_array($this->stato, [self::EMAIL_DA_CONFERMARE, self::IDENTITA_DA_VERIFICARE, self::IN_ATTESA], true);
    }

    public function confermaEmail(): bool
    {
        if (self::EMAIL_DA_CONFERMARE !== $this->stato || null === $this->tokenConfermaScadenza || $this->tokenConfermaScadenza <= new \DateTimeImmutable()) {
            return false;
        }
        $this->stato = self::IDENTITA_DA_VERIFICARE;
        $this->tokenConfermaHash = null;
        $this->tokenConfermaScadenza = null;

        return true;
    }

    public function identitaVerificata(User $supporto, string $documento, string $come, ?string $nota, string $tokenAnnullaHash): void
    {
        $this->verificataDa = $supporto;
        $this->verificaDocumento = $documento;
        $this->verificaCome = $come;
        $this->nota = $nota;
        $this->tokenAnnullaHash = $tokenAnnullaHash;
        $this->eseguibileIl = new \DateTimeImmutable(self::ATTESA);
        $this->stato = self::IN_ATTESA;
    }

    public function chiudi(string $stato, ?User $da = null, ?string $nota = null): void
    {
        $this->stato = $stato;
        $this->chiusaIl = new \DateTimeImmutable();
        $this->tokenConfermaHash = null;
        $this->tokenAnnullaHash = null;
        if (null !== $da) {
            $this->verificataDa ??= $da;
        }
        if (null !== $nota) {
            $this->nota = $nota;
        }
    }
}
