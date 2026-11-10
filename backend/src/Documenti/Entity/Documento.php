<?php

namespace App\Documenti\Entity;

use App\Account\Entity\User;
use App\Documenti\Enum\AnteprimaStato;
use App\Documenti\Enum\OcrStatus;
use App\Documenti\Repository\DocumentoRepository;
use App\Import\Entity\ImportSource;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Shared\Entity\Contract\TenantOwnedTrait;
use App\Shared\Entity\Contract\TimestampableTrait;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Documento della famiglia. Il file sta nello storage (Flysystem) sotto
 * storageKey, che NON viene mai esposto al frontend: il download passa sempre
 * dall'API, che verifica i permessi e decifra se serve.
 *
 * Documenti cifrati (categoria sensibile al momento del caricamento):
 * - il file nello storage è cifrato con una chiave propria (DEK);
 * - encryptedDek è la DEK cifrata con la chiave del tenant (mai in chiaro);
 * - il testo OCR va in ocrTextCifrato, ocrText resta null;
 * - hashSha256 contiene un HMAC con la chiave del tenant (hashTipo = hmac_sha256).
 *
 * I permessi documento:* sono dichiarati in config/packages/permissions.yaml.
 */
#[ORM\Entity(repositoryClass: DocumentoRepository::class)]
#[ORM\Table(name: 'documento')]
#[ORM\Index(name: 'idx_documento_tenant_created', columns: ['tenant_id', 'created_at'])]
#[ORM\Index(name: 'idx_documento_tenant_hash', columns: ['tenant_id', 'hash_sha256'])]
#[ORM\Index(name: 'idx_documento_tenant_md5', columns: ['tenant_id', 'hash_md5'])]
#[ORM\Index(name: 'idx_documento_sorgente_file', columns: ['import_source_id', 'sorgente_file_id'])]
#[ORM\HasLifecycleCallbacks]
class Documento implements OwnedResourceInterface
{
    use TenantOwnedTrait;
    use TimestampableTrait;

    public const HASH_SHA256 = 'sha256';
    public const HASH_HMAC = 'hmac_sha256';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Categoria::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Categoria $categoria;

    /** @var Collection<int, MembroFamiglia> vuota = documento della famiglia in generale */
    #[ORM\ManyToMany(targetEntity: MembroFamiglia::class)]
    #[ORM\JoinTable(name: 'documento_membro_famiglia')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $membri;

    #[ORM\Column(length: 200)]
    private string $titolo;

    /** Nome del file al caricamento (solo per il download, non usato nello storage). */
    #[ORM\Column(length: 255)]
    private string $nomeFileOriginale;

    #[ORM\Column(length: 255, unique: true)]
    private string $storageKey;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column(type: Types::BIGINT)]
    private int $dimensioneBytes;

    #[ORM\Column(length: 64)]
    private string $hashSha256;

    #[ORM\Column(length: 20)]
    private string $hashTipo;

    #[ORM\Column]
    private bool $cifrato;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedDek = null;

    #[ORM\Column(nullable: true)]
    private ?int $keyVersion = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ocrText = null;

    /** Testo OCR dei documenti cifrati (base64 di un blob DocumentCipher). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ocrTextCifrato = null;

    #[ORM\Column(length: 20, enumType: OcrStatus::class)]
    private OcrStatus $ocrStatus;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $ocrErrore = null;

    /**
     * Copia per la sola visualizzazione (Office → PDF, HEIC → JPEG), nella stessa
     * cartella dell'originale e cifrata con la stessa chiave se il documento è
     * cifrato. L'originale non cambia mai: download, export e Drive restano sul file vero.
     */
    #[ORM\Column(length: 20, enumType: AnteprimaStato::class, options: ['default' => 'non_serve'])]
    private AnteprimaStato $anteprimaStato = AnteprimaStato::NON_SERVE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $anteprimaKey = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $anteprimaMime = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $anteprimaErrore = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dataDocumento = null;

    /** Solo il dato, per una futura policy di retention: nessuna cancellazione automatica. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dataConservazioneFino = null;

    /**
     * HMAC (chiave del tenant) dell'MD5 del contenuto: permette di riconoscere un
     * file di Google Drive già presente PRIMA di scaricarlo (Drive espone l'md5Checksum).
     * Mai l'MD5 in chiaro, per lo stesso motivo dell'HMAC sui documenti cifrati.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hashMd5 = null;

    /** Sorgente da cui è stato importato (null = caricato a mano). */
    #[ORM\ManyToOne(targetEntity: ImportSource::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ImportSource $importSource = null;

    /** Id del file nella sorgente esterna e sua versione (data di modifica) al momento dell'import. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sorgenteFileId = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sorgenteVersione = null;

    public const PROPOSTA_APERTA = 'aperta';
    public const PROPOSTA_ACCETTATA = 'accettata';
    public const PROPOSTA_IGNORATA = 'ignorata';

    /**
     * Scadenza trovata nel testo ({data, tipo} in JSON): estratta dal contenuto,
     * quindi cifrata come il testo OCR se il documento è cifrato
     * (DocumentService::storeScadenzaProposta).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scadenzaProposta = null;

    /** aperta | accettata | ignorata; null = nessuna proposta. Non rivela la data. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $scadenzaPropostaStato = null;
    public function __construct(Uuid $id, Tenant $tenant, User $owner, Categoria $categoria, string $titolo, string $nomeFileOriginale)
    {
        $this->id = $id;
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->categoria = $categoria;
        $this->titolo = $titolo;
        $this->nomeFileOriginale = $nomeFileOriginale;
        $this->membri = new ArrayCollection();
        $this->ocrStatus = OcrStatus::IN_CODA;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCategoria(): Categoria
    {
        return $this->categoria;
    }

    public function setCategoria(Categoria $categoria): void
    {
        $this->categoria = $categoria;
    }

    /** @return Collection<int, MembroFamiglia> */
    public function getMembri(): Collection
    {
        return $this->membri;
    }

    /** @param iterable<MembroFamiglia> $membri */
    public function replaceMembri(iterable $membri): void
    {
        $this->membri->clear();
        foreach ($membri as $membro) {
            if (!$this->membri->contains($membro)) {
                $this->membri->add($membro);
            }
        }
    }

    public function getTitolo(): string
    {
        return $this->titolo;
    }

    public function setTitolo(string $titolo): void
    {
        $this->titolo = $titolo;
    }

    public function getNomeFileOriginale(): string
    {
        return $this->nomeFileOriginale;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getDimensioneBytes(): int
    {
        return $this->dimensioneBytes;
    }

    public function getHashSha256(): string
    {
        return $this->hashSha256;
    }

    public function getHashTipo(): string
    {
        return $this->hashTipo;
    }

    public function isCifrato(): bool
    {
        return $this->cifrato;
    }

    public function getEncryptedDek(): ?string
    {
        return $this->encryptedDek;
    }

    public function getKeyVersion(): ?int
    {
        return $this->keyVersion;
    }

    /** Dati del file memorizzato (chiamato al caricamento e alla ricifratura). */
    public function attachStoredFile(string $storageKey, string $mimeType, int $size, string $hash, string $hashTipo, bool $cifrato, ?string $encryptedDek, ?int $keyVersion): void
    {
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->dimensioneBytes = $size;
        $this->hashSha256 = $hash;
        $this->hashTipo = $hashTipo;
        $this->cifrato = $cifrato;
        $this->encryptedDek = $encryptedDek;
        $this->keyVersion = $keyVersion;
    }

    public function rewrapDek(string $encryptedDek, int $keyVersion): void
    {
        $this->encryptedDek = $encryptedDek;
        $this->keyVersion = $keyVersion;
    }

    public function getOcrText(): ?string
    {
        return $this->ocrText;
    }

    public function getOcrTextCifrato(): ?string
    {
        return $this->ocrTextCifrato;
    }

    public function getOcrStatus(): OcrStatus
    {
        return $this->ocrStatus;
    }

    public function getOcrErrore(): ?string
    {
        return $this->ocrErrore;
    }

    public function queueOcr(bool $applicable): void
    {
        $this->ocrStatus = $applicable ? OcrStatus::IN_CODA : OcrStatus::NON_APPLICABILE;
        $this->ocrErrore = null;
    }

    public function completeOcr(?string $plainText, ?string $encryptedText): void
    {
        $this->ocrText = $plainText;
        $this->ocrTextCifrato = $encryptedText;
        $this->ocrStatus = OcrStatus::COMPLETATO;
        $this->ocrErrore = null;
    }

    public function getScadenzaPropostaRaw(): ?string
    {
        return $this->scadenzaProposta;
    }

    public function getScadenzaPropostaStato(): ?string
    {
        return $this->scadenzaPropostaStato;
    }

    public function setScadenzaPropostaRaw(string $raw): void
    {
        $this->scadenzaProposta = $raw;
        $this->scadenzaPropostaStato ??= self::PROPOSTA_APERTA;
    }

    /** Accettata (creata la scadenza) o ignorata: il dato estratto non serve più. */
    public function chiudiScadenzaProposta(string $esito): void
    {
        if (self::PROPOSTA_APERTA === $this->scadenzaPropostaStato) {
            $this->scadenzaPropostaStato = $esito;
            $this->scadenzaProposta = null;
        }
    }

    public function failOcr(string $reason): void
    {
        $this->ocrStatus = OcrStatus::FALLITO;
        $this->ocrErrore = mb_substr($reason, 0, 500);
    }

    public function queueAnteprima(bool $serve): void
    {
        $this->anteprimaStato = $serve ? AnteprimaStato::IN_CODA : AnteprimaStato::NON_SERVE;
        $this->anteprimaErrore = null;
    }

    public function completaAnteprima(string $key, string $mime): void
    {
        $this->anteprimaKey = $key;
        $this->anteprimaMime = $mime;
        $this->anteprimaStato = AnteprimaStato::PRONTA;
        $this->anteprimaErrore = null;
    }

    public function failAnteprima(string $reason): void
    {
        $this->anteprimaStato = AnteprimaStato::FALLITA;
        $this->anteprimaErrore = mb_substr($reason, 0, 500);
    }

    public function getAnteprimaStato(): AnteprimaStato
    {
        return $this->anteprimaStato;
    }

    public function getAnteprimaKey(): ?string
    {
        return $this->anteprimaKey;
    }

    /** Tipo di ciò che si vede nella scheda Anteprima (l'originale se non serve una copia). */
    public function getAnteprimaMime(): ?string
    {
        return AnteprimaStato::NON_SERVE === $this->anteprimaStato ? $this->mimeType : $this->anteprimaMime;
    }

    public function getAnteprimaErrore(): ?string
    {
        return $this->anteprimaErrore;
    }

    public function getDataDocumento(): ?\DateTimeImmutable
    {
        return $this->dataDocumento;
    }

    public function setDataDocumento(?\DateTimeImmutable $dataDocumento): void
    {
        $this->dataDocumento = $dataDocumento;
    }

    public function getDataConservazioneFino(): ?\DateTimeImmutable
    {
        return $this->dataConservazioneFino;
    }

    public function setDataConservazioneFino(?\DateTimeImmutable $data): void
    {
        $this->dataConservazioneFino = $data;
    }

    public function getHashMd5(): ?string
    {
        return $this->hashMd5;
    }

    public function setHashMd5(string $hashMd5): void
    {
        $this->hashMd5 = $hashMd5;
    }

    public function getImportSource(): ?ImportSource
    {
        return $this->importSource;
    }

    public function getSorgenteFileId(): ?string
    {
        return $this->sorgenteFileId;
    }

    public function getSorgenteVersione(): ?string
    {
        return $this->sorgenteVersione;
    }

    public function markImported(ImportSource $source, string $fileId, string $versione): void
    {
        $this->importSource = $source;
        $this->sorgenteFileId = $fileId;
        $this->sorgenteVersione = $versione;
    }
}
