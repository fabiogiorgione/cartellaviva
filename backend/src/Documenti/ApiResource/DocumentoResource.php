<?php

namespace App\Documenti\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Documenti\Entity\Documento;
use App\Documenti\State\DocumentoProcessor;
use App\Documenti\State\DocumentoProvider;
use App\Documenti\State\DocumentoUploadProcessor;
use App\Tenancy\Entity\MembroFamiglia;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Documenti del tenant. Nessuna operazione espone lo storage: il file si scarica
 * da /api/tenants/{tenantId}/documenti/{id}/file (DocumentoFileController).
 *
 *   GET    /api/tenants/{tenantId}/documenti            documento:read   (?categoriaId&membroId&q&modo=significato&page&itemsPerPage)
 *   POST   /api/tenants/{tenantId}/documenti            documento:create (multipart: file, categoriaId, membriIds[], titolo, dataDocumento)
 *   GET    /api/tenants/{tenantId}/documenti/{id}       documento:read
 *   PATCH  /api/tenants/{tenantId}/documenti/{id}       documento:write  (own/all)
 *   DELETE /api/tenants/{tenantId}/documenti/{id}       documento:delete (own/all)
 */
#[ApiResource(
    shortName: 'Documento',
    operations: [
        new GetCollection(uriTemplate: '/tenants/{tenantId}/documenti', uriVariables: ['tenantId'], provider: DocumentoProvider::class, paginationEnabled: false,
            normalizationContext: ['groups' => ['documento:list']]),
        new Post(
            uriTemplate: '/tenants/{tenantId}/documenti',
            uriVariables: ['tenantId'],
            inputFormats: ['multipart' => ['multipart/form-data']],
            read: false,
            deserialize: false,
            validate: false,
            processor: DocumentoUploadProcessor::class,
            openapi: new Model\Operation(
                summary: 'Carica un documento',
                requestBody: new Model\RequestBody(content: new \ArrayObject([
                    'multipart/form-data' => ['schema' => ['type' => 'object', 'required' => ['file', 'categoriaId'], 'properties' => [
                        'file' => ['type' => 'string', 'format' => 'binary'],
                        'categoriaId' => ['type' => 'string', 'format' => 'uuid'],
                        'membriIds[]' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']],
                        'titolo' => ['type' => 'string'],
                        'dataDocumento' => ['type' => 'string', 'format' => 'date'],
                    ]]],
                ])),
            ),
        ),
        new Get(uriTemplate: '/tenants/{tenantId}/documenti/{id}', uriVariables: ['tenantId', 'id'], provider: DocumentoProvider::class),
        new Patch(uriTemplate: '/tenants/{tenantId}/documenti/{id}', uriVariables: ['tenantId', 'id'], provider: DocumentoProvider::class, processor: DocumentoProcessor::class),
        new Delete(uriTemplate: '/tenants/{tenantId}/documenti/{id}', uriVariables: ['tenantId', 'id'], provider: DocumentoProvider::class, processor: DocumentoProcessor::class),
    ],
    normalizationContext: ['groups' => ['documento:list', 'documento:read']],
    denormalizationContext: ['groups' => ['documento:write']],
)]
final class DocumentoResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['documento:list'])]
    public ?string $id = null;

    #[ApiProperty(identifier: false)]
    #[Groups(['documento:list'])]
    public ?string $tenantId = null;

    #[Groups(['documento:list', 'documento:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public string $titolo = '';

    #[Groups(['documento:list', 'documento:write'])]
    public ?string $categoriaId = null;

    /** @var array{id: string, nome: string, icona: string, colore: string, isSensibile: bool}|null */
    #[Groups(['documento:list'])]
    public ?array $categoria = null;

    /** @var list<string> */
    #[Groups(['documento:list', 'documento:write'])]
    public array $membriIds = [];

    /** @var list<array{id: string, nome: string}> */
    #[Groups(['documento:list'])]
    public array $membri = [];

    #[Groups(['documento:list'])]
    public ?string $ownerId = null;

    #[Groups(['documento:list'])]
    public ?string $ownerName = null;

    #[Groups(['documento:list'])]
    public ?string $nomeFile = null;

    #[Groups(['documento:list'])]
    public ?string $mimeType = null;

    #[Groups(['documento:list'])]
    public int $dimensioneBytes = 0;

    #[Groups(['documento:list'])]
    public bool $cifrato = false;

    #[Groups(['documento:list'])]
    public ?string $ocrStatus = null;

    #[Groups(['documento:read'])]
    public ?string $ocrErrore = null;

    /** Solo nel dettaglio (decifrato se il documento è cifrato; la lettura finisce nell'audit). */
    #[Groups(['documento:read'])]
    public ?string $ocrText = null;

    /**
     * Solo nel dettaglio: scadenza trovata nel testo e ancora da decidere
     * ({data, tipo, titoloSuggerito, ...}, vedi ScadenzaController::proposta).
     *
     * @var array<string, mixed>|null
     */
    #[Groups(['documento:read'])]
    public ?array $scadenzaProposta = null;

    /**
     * Solo nel dettaglio: avviso di pagamento trovato nel testo e ancora da decidere
     * (stessa forma di un elemento di GET /pagamenti-proposte); solo per chi può creare pagamenti.
     *
     * @var array<string, mixed>|null
     */
    #[Groups(['documento:read'])]
    public ?array $pagamentoProposta = null;

    /**
     * Solo nel dettaglio: pagamenti collegati al documento ("Usato in: Pagamenti"),
     * come origine o come ricevuta; vuoto se l'utente non può leggere i pagamenti.
     *
     * @var list<array{id: string, titolo: string, stato: string, ruolo: string}>
     */
    #[Groups(['documento:read'])]
    public array $pagamenti = [];

    /**
     * Solo nel dettaglio e solo per i documenti non cifrati: dati estratti dal
     * testo ({emittente, data, importo, numero, intestatario}), tag e riferimenti
     * (targa, POD, polizza...). Null = non ancora analizzato o documento cifrato.
     *
     * @var array{dati: array<string, ?string>, tag: list<string>, riferimenti: list<string>, aggiornatoIl: string}|null
     */
    #[Groups(['documento:read'])]
    public ?array $indice = null;

    #[Groups(['documento:list', 'documento:write'])]
    #[Assert\Date]
    public ?string $dataDocumento = null;

    #[Groups(['documento:list', 'documento:write'])]
    #[Assert\Date]
    public ?string $dataConservazioneFino = null;

    /** Scheda "Anteprima": non_serve (si mostra l'originale), in_coda, pronta, fallita. */
    #[Groups(['documento:list'])]
    public ?string $anteprimaStato = null;

    /** Tipo di ciò che si vede nell'anteprima (application/pdf o un'immagine). */
    #[Groups(['documento:list'])]
    public ?string $anteprimaMime = null;

    #[Groups(['documento:read'])]
    public ?string $anteprimaErrore = null;

    /** Percorso API per scaricare il file (mai l'indirizzo dello storage). */
    #[Groups(['documento:list'])]
    public ?string $downloadUrl = null;

    #[Groups(['documento:list'])]
    public ?\DateTimeImmutable $createdAt = null;

    #[Groups(['documento:list'])]
    public ?\DateTimeImmutable $updatedAt = null;

    /**
     * Solo nella risposta al caricamento: documenti dello stesso tenant con lo
     * stesso contenuto (segnalazione, il caricamento non viene bloccato).
     *
     * @var list<array{id: string, titolo: string, createdAt: string}>
     */
    #[Groups(['documento:list'])]
    public array $possibiliDuplicati = [];

    public static function fromEntity(Documento $d, ?string $ocrText = null): self
    {
        $r = new self();
        $r->id = (string) $d->getId();
        $r->tenantId = (string) $d->getTenant()->getId();
        $r->titolo = $d->getTitolo();
        $c = $d->getCategoria();
        $r->categoriaId = (string) $c->getId();
        $r->categoria = ['id' => (string) $c->getId(), 'nome' => $c->getNome(), 'icona' => $c->getIcona(), 'colore' => $c->getColore(), 'isSensibile' => $c->isSensibile()];
        $r->membriIds = array_values(array_map(static fn (MembroFamiglia $m) => (string) $m->getId(), $d->getMembri()->toArray()));
        $r->membri = array_values(array_map(static fn (MembroFamiglia $m) => ['id' => (string) $m->getId(), 'nome' => $m->getDisplayName()], $d->getMembri()->toArray()));
        $r->ownerId = (string) $d->getOwner()->getId();
        $r->ownerName = $d->getOwner()->getFullName();
        $r->nomeFile = $d->getNomeFileOriginale();
        $r->mimeType = $d->getMimeType();
        $r->dimensioneBytes = $d->getDimensioneBytes();
        $r->cifrato = $d->isCifrato();
        $r->ocrStatus = $d->getOcrStatus()->value;
        $r->ocrErrore = $d->getOcrErrore();
        $r->ocrText = $ocrText;
        $r->anteprimaStato = $d->getAnteprimaStato()->value;
        $r->anteprimaMime = $d->getAnteprimaMime();
        $r->anteprimaErrore = $d->getAnteprimaErrore();
        $r->dataDocumento = $d->getDataDocumento()?->format('Y-m-d');
        $r->dataConservazioneFino = $d->getDataConservazioneFino()?->format('Y-m-d');
        $r->downloadUrl = sprintf('/api/tenants/%s/documenti/%s/file', $r->tenantId, $r->id);
        $r->createdAt = $d->getCreatedAt();
        $r->updatedAt = $d->getUpdatedAt();

        return $r;
    }
}
