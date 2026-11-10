<?php

namespace App\Import\Entity;

use App\Account\Entity\User;
use App\Import\Enum\ImportProvider;
use App\Import\Enum\ImportSourceStato;
use App\Import\Enum\ImportStrategia;
use App\Import\Repository\ImportSourceRepository;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\Attribute\PermissionResource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Sorgente esterna di documenti (es. una cartella di Google Drive) collegata a un tenant.
 *
 * I token OAuth sono cifrati come i documenti sensibili (App\Import\ImportTokenVault):
 * chiave casuale propria (encryptedDek, cifrata con la chiave del tenant) e token
 * cifrati con quella chiave. Nel DB non c'è mai un token in chiaro.
 *
 * Il collegamento OAuth usa uno "state" casuale monouso: nel DB ne resta solo
 * l'hash, legato alla sorgente, all'utente che l'ha avviato e a una scadenza.
 */
#[ORM\Entity(repositoryClass: ImportSourceRepository::class)]
#[ORM\Table(name: 'import_source')]
#[ORM\Index(name: 'idx_import_source_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_import_source_oauth_state', columns: ['oauth_state_hash'])]
#[ORM\HasLifecycleCallbacks]
#[PermissionResource('import', 'Importazione documenti', [
    'manage' => 'Collega sorgenti esterne (es. Google Drive) e avvia le importazioni',
])]
class ImportSource implements TenantScopedInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    /** Utente che ha creato la sorgente: diventa il proprietario dei documenti importati. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creato_da_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $creatoDa;

    #[ORM\Column(length: 30, enumType: ImportProvider::class)]
    private ImportProvider $provider;

    /** Access token cifrato (base64 di un blob DocumentCipher). */
    #[ORM\Column(name: 'oauth_access_token', type: Types::TEXT, nullable: true)]
    private ?string $oauthAccessToken = null;

    /** Refresh token cifrato (base64 di un blob DocumentCipher). */
    #[ORM\Column(name: 'oauth_refresh_token', type: Types::TEXT, nullable: true)]
    private ?string $oauthRefreshToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $oauthTokenScadenza = null;

    /** Chiave dei token, cifrata con la chiave del tenant. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedDek = null;

    #[ORM\Column(nullable: true)]
    private ?int $keyVersion = null;

    /** Account esterno collegato (solo per mostrarlo all'utente). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $accountEsterno = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $oauthStateHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $oauthStateScadenza = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'oauth_state_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $oauthStateUser = null;

    /** Dove tornare dopo l'OAuth: una chiave di ImportSourceService::RITORNI, mai un URL libero. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $oauthRitorno = null;

    #[ORM\Column(length: 255)]
    private string $cartellaRadiceId;

    #[ORM\Column(length: 20, enumType: ImportStrategia::class)]
    private ImportStrategia $strategia;

    #[ORM\Column(length: 20, enumType: ImportSourceStato::class)]
    private ImportSourceStato $stato;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ultimaSincronizzazione = null;

    /** Valorizzato da /sync, azzerato a fine job: evita due importazioni contemporanee. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sincronizzazioneRichiestaIl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $ultimoErrore = null;

    /** @var array<string, int>|null conteggi dell'ultima sincronizzazione */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $ultimoEsito = null;

    public function __construct(Tenant $tenant, User $creatoDa, ImportProvider $provider, string $cartellaRadiceId, ImportStrategia $strategia)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->creatoDa = $creatoDa;
        $this->provider = $provider;
        $this->cartellaRadiceId = $cartellaRadiceId;
        $this->strategia = $strategia;
        $this->stato = ImportSourceStato::NON_CONFIGURATO;
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

    public function getCreatoDa(): User
    {
        return $this->creatoDa;
    }

    public function getProvider(): ImportProvider
    {
        return $this->provider;
    }

    public function getCartellaRadiceId(): string
    {
        return $this->cartellaRadiceId;
    }

    public function getStrategia(): ImportStrategia
    {
        return $this->strategia;
    }

    public function getStato(): ImportSourceStato
    {
        return $this->stato;
    }

    public function getAccountEsterno(): ?string
    {
        return $this->accountEsterno;
    }

    public function isCollegata(): bool
    {
        return null !== $this->oauthRefreshToken;
    }

    public function getOauthAccessToken(): ?string
    {
        return $this->oauthAccessToken;
    }

    public function getOauthRefreshToken(): ?string
    {
        return $this->oauthRefreshToken;
    }

    public function getOauthTokenScadenza(): ?\DateTimeImmutable
    {
        return $this->oauthTokenScadenza;
    }

    public function getEncryptedDek(): ?string
    {
        return $this->encryptedDek;
    }

    public function getKeyVersion(): ?int
    {
        return $this->keyVersion;
    }

    /** Token GIÀ cifrati (ImportTokenVault). */
    public function storeTokens(string $encryptedDek, int $keyVersion, string $accessToken, string $refreshToken, ?\DateTimeImmutable $scadenza): void
    {
        $this->encryptedDek = $encryptedDek;
        $this->keyVersion = $keyVersion;
        $this->oauthAccessToken = $accessToken;
        $this->oauthRefreshToken = $refreshToken;
        $this->oauthTokenScadenza = $scadenza;
    }

    public function rewrapDek(string $encryptedDek, int $keyVersion): void
    {
        $this->encryptedDek = $encryptedDek;
        $this->keyVersion = $keyVersion;
    }

    public function beginOAuth(string $stateHash, User $user, \DateTimeImmutable $scadenza, string $ritorno): void
    {
        $this->oauthStateHash = $stateHash;
        $this->oauthStateUser = $user;
        $this->oauthStateScadenza = $scadenza;
        $this->oauthRitorno = $ritorno;
    }

    public function getOauthRitorno(): ?string
    {
        return $this->oauthRitorno;
    }

    /** Lo state vale una sola volta: si cancella comunque, prima di verificarne la scadenza. */
    public function consumeOAuthState(): ?User
    {
        $user = null !== $this->oauthStateScadenza && $this->oauthStateScadenza > new \DateTimeImmutable() ? $this->oauthStateUser : null;
        $this->oauthStateHash = null;
        $this->oauthStateUser = null;
        $this->oauthStateScadenza = null;

        return $user;
    }

    public function markConnected(?string $accountEsterno): void
    {
        $this->accountEsterno = null !== $accountEsterno ? mb_substr($accountEsterno, 0, 255) : null;
        $this->stato = ImportSourceStato::ATTIVO;
        $this->ultimoErrore = null;
    }

    public function getUltimaSincronizzazione(): ?\DateTimeImmutable
    {
        return $this->ultimaSincronizzazione;
    }

    public function getSincronizzazioneRichiestaIl(): ?\DateTimeImmutable
    {
        return $this->sincronizzazioneRichiestaIl;
    }

    /** Una sincronizzazione richiesta da più di un'ora si considera interrotta (worker riavviato). */
    public function isSincronizzazioneInCorso(): bool
    {
        return null !== $this->sincronizzazioneRichiestaIl && $this->sincronizzazioneRichiestaIl > new \DateTimeImmutable('-1 hour');
    }

    public function requestSync(): void
    {
        $this->sincronizzazioneRichiestaIl = new \DateTimeImmutable();
    }

    /**
     * Chi l'aveva collegata ha lasciato il tenant: la sorgente passa a un altro
     * utente ma i token (del suo account Google) vengono cancellati.
     */
    public function handOverTo(User $newOwner): void
    {
        $this->creatoDa = $newOwner;
        $this->disconnect('Chi aveva collegato Google Drive non fa più parte della famiglia: ricollega un account.');
    }

    /** Cancella token e stato OAuth; la sorgente resta, da ricollegare. */
    public function disconnect(string $motivo): void
    {
        $this->oauthAccessToken = null;
        $this->oauthRefreshToken = null;
        $this->oauthTokenScadenza = null;
        $this->encryptedDek = null;
        $this->keyVersion = null;
        $this->accountEsterno = null;
        $this->oauthStateHash = null;
        $this->oauthStateUser = null;
        $this->oauthStateScadenza = null;
        $this->sincronizzazioneRichiestaIl = null;
        $this->oauthRitorno = null;
        $this->stato = ImportSourceStato::NON_CONFIGURATO;
        $this->ultimoErrore = $motivo;
    }

    public function cancelSyncRequest(): void
    {
        $this->sincronizzazioneRichiestaIl = null;
    }

    /** @param array<string, int> $esito */
    public function completeSync(array $esito): void
    {
        $this->sincronizzazioneRichiestaIl = null;
        $this->ultimaSincronizzazione = new \DateTimeImmutable();
        $this->ultimoEsito = $esito;
        $this->ultimoErrore = null;
        $this->stato = ImportSourceStato::ATTIVO;
    }

    /** @param array<string, int>|null $esito */
    public function failSync(string $reason, ?array $esito = null): void
    {
        $this->sincronizzazioneRichiestaIl = null;
        $this->ultimoErrore = mb_substr($reason, 0, 500);
        $this->ultimoEsito = $esito;
        $this->stato = ImportSourceStato::ERRORE;
    }

    public function getUltimoErrore(): ?string
    {
        return $this->ultimoErrore;
    }

    /** @return array<string, int>|null */
    public function getUltimoEsito(): ?array
    {
        return $this->ultimoEsito;
    }
}
