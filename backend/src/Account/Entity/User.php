<?php

namespace App\Account\Entity;

use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Repository\UserRepository;
use App\Shared\Entity\Contract\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Account di accesso. Non porta permessi
 * propri: tutto passa da Membership -> Role, sempre legato a un tenant.
 * L'unica eccezione è il flag superAdmin (amministratore della piattaforma).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(options: ['default' => false])]
    private bool $superAdmin = false;

    /**
     * Ruolo nel portale diverso dal superadmin: "supporto" (recuperi d'accesso,
     * metadati di tenant e utenti) o "sola_lettura" (salute e registro). Mai documenti.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $platformRole = null;

    /** Dopo un recupero dell'accesso: al prossimo accesso va riattivata la 2FA. */
    #[ORM\Column(options: ['default' => false])]
    private bool $twoFactorObbligatoria = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /**
     * Null finché l'utente non apre il link di verifica. Gli account creati da
     * invito o con Google nascono verificati (la casella è già dimostrata).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificataIl = null;

    /**
     * Ultimo cambio di password o email: i JWT emessi prima non valgono più
     * (JwtCredenzialiListener), anche se non ancora scaduti.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $credenzialiCambiateIl = null;

    /**
     * Preferenze dell'accoglienza: {introVista: bool, primiPassiNascosti: list<tenantId>}.
     * Nessun dato riservato.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $preferenze = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** Identificativo stabile dell'account Google ("sub" dell'ID token). */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $googleSubject = null;

    /** Segreto TOTP attivo, cifrato (SecretEncryptor). Null = 2FA non attiva. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpSecret = null;

    /** Segreto generato in fase di attivazione, non ancora confermato con un codice. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpPendingSecret = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    /** Ultimo intervallo TOTP accettato: impedisce di riusare lo stesso codice. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $totpLastTimestep = null;

    /** Valorizzato quando l'anagrafica è completa e valida (onboarding concluso). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $profileCompletedAt = null;

    #[ORM\OneToOne(targetEntity: Anagrafica::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Anagrafica $anagrafica = null;

    public function __construct(string $email, string $firstName, string $lastName)
    {
        $this->id = Uuid::v7();
        $this->email = self::normalizeEmail($email);
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->superAdmin ? ['ROLE_USER', self::ROLE_SUPER_ADMIN] : ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function rename(string $firstName, string $lastName): void
    {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    public function setSuperAdmin(bool $superAdmin): void
    {
        $this->superAdmin = $superAdmin;
    }

    public const PLATFORM_SUPERADMIN = 'superadmin';
    public const PLATFORM_SUPPORTO = 'supporto';
    public const PLATFORM_SOLA_LETTURA = 'sola_lettura';

    /** superadmin | supporto | sola_lettura | null (nessun accesso al portale). */
    public function getPlatformRole(): ?string
    {
        return $this->superAdmin ? self::PLATFORM_SUPERADMIN : $this->platformRole;
    }

    /** Il superadmin resta il flag dedicato; qui si assegnano supporto e sola lettura. */
    public function setPlatformRole(?string $role): void
    {
        if (null !== $role && !\in_array($role, [self::PLATFORM_SUPPORTO, self::PLATFORM_SOLA_LETTURA], true)) {
            throw new \InvalidArgumentException('Ruolo di piattaforma non valido.');
        }
        $this->platformRole = $role;
    }

    public function isTwoFactorObbligatoria(): bool
    {
        return $this->twoFactorObbligatoria;
    }

    /** Recupero dell'accesso completato: 2FA disattivata, da riattivare al prossimo accesso. */
    public function resetTwoFactorPerRecupero(): void
    {
        $this->disableTwoFactor();
        $this->twoFactorObbligatoria = true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function isEmailVerificata(): bool
    {
        return null !== $this->emailVerificataIl;
    }

    public function getEmailVerificataIl(): ?\DateTimeImmutable
    {
        return $this->emailVerificataIl;
    }

    public function segnaEmailVerificata(): void
    {
        $this->emailVerificataIl ??= new \DateTimeImmutable();
    }

    /** Cambio fatto dal portale: il nuovo indirizzo va confermato di nuovo. */
    public function impostaEmailDaConfermare(string $email): void
    {
        $this->email = self::normalizeEmail($email);
        $this->emailVerificataIl = null;
        $this->segnaCredenzialiCambiate();
    }

    /** Dominio riservato (RFC 2606) per gli account eliminati: l'indirizzo vero torna libero. */
    public const DOMINIO_ELIMINATI = 'utenti-eliminati.invalid';

    public function isEliminato(): bool
    {
        return str_ends_with($this->email, '@'.self::DOMINIO_ELIMINATI);
    }

    /**
     * Eliminazione dal portale: niente dati personali né credenziali, account
     * disattivato. La riga resta per i vincoli e per la coerenza del registro.
     */
    public function anonimizza(): void
    {
        $this->email = sprintf('%s@%s', $this->id->toRfc4122(), self::DOMINIO_ELIMINATI);
        $this->firstName = 'Utente';
        $this->lastName = 'eliminato';
        $this->password = '';
        $this->googleSubject = null;
        $this->platformRole = null;
        $this->preferenze = null;
        $this->active = false;
        $this->emailVerificataIl = null;
        $this->disableTwoFactor();
        $this->segnaCredenzialiCambiate();
    }

    /** Il nuovo indirizzo è confermato dal link: risulta già verificato. */
    public function cambiaEmail(string $email): void
    {
        $this->email = self::normalizeEmail($email);
        $this->emailVerificataIl = new \DateTimeImmutable();
        $this->segnaCredenzialiCambiate();
    }

    public function isIntroVista(): bool
    {
        return true === ($this->preferenze['introVista'] ?? false);
    }

    public function segnaIntroVista(): void
    {
        $this->preferenze = ['introVista' => true] + ($this->preferenze ?? []);
    }

    public function isPrimiPassiNascosti(string $tenantId): bool
    {
        return \in_array($tenantId, $this->preferenze['primiPassiNascosti'] ?? [], true);
    }

    public function nascondiPrimiPassi(string $tenantId, bool $nascondi): void
    {
        $lista = array_values(array_diff($this->preferenze['primiPassiNascosti'] ?? [], [$tenantId]));
        if ($nascondi) {
            $lista[] = $tenantId;
        }
        $this->preferenze = ['primiPassiNascosti' => $lista] + ($this->preferenze ?? []);
    }

    public function getCredenzialiCambiateIl(): ?\DateTimeImmutable
    {
        return $this->credenzialiCambiateIl;
    }

    public function segnaCredenzialiCambiate(): void
    {
        // Al secondo intero (come "iat" del JWT): il DB arrotonderebbe in su e
        // invaliderebbe anche il token emesso subito dopo, nella stessa richiesta.
        $this->credenzialiCambiateIl = new \DateTimeImmutable('@'.time());
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function markLoggedIn(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    /** Gli account creati con Google non hanno password finché non la impostano. */
    public function hasPassword(): bool
    {
        return '' !== $this->password;
    }

    public function getGoogleSubject(): ?string
    {
        return $this->googleSubject;
    }

    public function linkGoogle(?string $subject): void
    {
        $this->googleSubject = $subject;
    }

    public function isTwoFactorEnabled(): bool
    {
        return null !== $this->totpSecret;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function getTotpPendingSecret(): ?string
    {
        return $this->totpPendingSecret;
    }

    public function setTotpPendingSecret(?string $encryptedSecret): void
    {
        $this->totpPendingSecret = $encryptedSecret;
    }

    public function enableTwoFactor(string $encryptedSecret, int $timestep): void
    {
        $this->totpSecret = $encryptedSecret;
        $this->totpPendingSecret = null;
        $this->totpEnabledAt = new \DateTimeImmutable();
        $this->totpLastTimestep = $timestep;
        $this->twoFactorObbligatoria = false;
    }

    public function disableTwoFactor(): void
    {
        $this->totpSecret = null;
        $this->totpPendingSecret = null;
        $this->totpEnabledAt = null;
        $this->totpLastTimestep = null;
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    public function getTotpLastTimestep(): ?int
    {
        return $this->totpLastTimestep;
    }

    public function setTotpLastTimestep(int $timestep): void
    {
        $this->totpLastTimestep = $timestep;
    }

    public function isProfileCompleted(): bool
    {
        return null !== $this->profileCompletedAt;
    }

    public function markProfileCompleted(): void
    {
        $this->profileCompletedAt ??= new \DateTimeImmutable();
    }

    public function getAnagrafica(): ?Anagrafica
    {
        return $this->anagrafica;
    }

    public function setAnagrafica(?Anagrafica $anagrafica): void
    {
        $this->anagrafica = $anagrafica;
    }
}
