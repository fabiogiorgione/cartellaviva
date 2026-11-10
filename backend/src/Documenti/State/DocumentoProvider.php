<?php

namespace App\Documenti\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Documenti\ApiResource\DocumentoResource;
use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Indice\IndiceDocumenti;
use App\Documenti\Indice\RicercaPerSignificato;
use App\Documenti\Repository\DocumentoRepository;
use App\Scadenze\Controller\ScadenzaController;
use App\Shared\State\TotaleRisultatiListener;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lista e dettaglio dei documenti. La lista è SEMPRE limitata al tenant
 * dell'URL, su cui l'utente deve avere una Membership attiva con documento:read.
 *
 * @implements ProviderInterface<DocumentoResource>
 */
final class DocumentoProvider implements ProviderInterface
{
    private const DEFAULT_PER_PAGE = 30;
    private const MAX_PER_PAGE = 100;
    private const MAX_SIGNIFICATO = 100;

    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly DocumentoRepository $documenti,
        private readonly DocumentService $documents,
        private readonly DocumentAudit $audit,
        private readonly FiltriElenco $filtriElenco,
        private readonly RicercaPerSignificato $ricerca,
        private readonly IndiceDocumenti $indice,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], 'documento:read');
            $query = ($context['request'] ?? null)?->query;
            $page = max(1, (int) ($query?->get('page') ?? 1));
            $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($query?->get('itemsPerPage') ?? self::DEFAULT_PER_PAGE)));
            $filtri = $this->filtriElenco->leggi($tenant, $context['request'] ?? null);
            $q = $query?->get('q');
            // Per significato: in ordine di pertinenza, al massimo MAX_SIGNIFICATO risultati divisi in pagine.
            // Null = non disponibile (modello spento, Ollama lento): ricerca per parole di sempre.
            $perSignificato = 'significato' === $query?->get('modo') && \is_string($q)
                ? $this->ricerca->cerca($tenant, $q, $filtri, self::MAX_SIGNIFICATO)
                : null;
            $result = null !== $perSignificato
                ? ['items' => \array_slice($perSignificato, ($page - 1) * $perPage, $perPage), 'total' => \count($perSignificato)]
                : $this->documenti->search($tenant, $filtri + ['q' => $q], $page, $perPage);
            $this->audit->list($tenant, $result['items']);
            // Il totale va nell'header X-Total-Count (TotaleRisultatiListener): l'elenco resta un array.
            $context['request']?->attributes->set(TotaleRisultatiListener::ATTRIBUTO, $result['total']);

            return array_map(static fn ($d) => DocumentoResource::fromEntity($d), $result['items']);
        }

        $tenant = $this->tenants->findByPublicId((string) $uriVariables['tenantId']) ?? throw new NotFoundHttpException('Tenant non trovato.');
        $this->access->require('documento:read', $tenant);
        $documento = $this->documenti->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Documento non trovato.');
        $this->access->require('documento:read', $documento);

        if ($operation instanceof Get) {
            // Dettaglio: include il testo OCR (decifrato in memoria se cifrato).
            $this->audit->read($documento, 'documento.read');

            $resource = DocumentoResource::fromEntity($documento, $this->documents->readOcrText($documento));
            $proposta = $this->documents->readScadenzaProposta($documento);
            $resource->scadenzaProposta = null !== $proposta ? ScadenzaController::proposta($documento, $proposta) : null;
            $resource->indice = $documento->isCifrato() ? null : $this->indice->leggi($documento);

            return $resource;
        }

        return DocumentoResource::fromEntity($documento);
    }
}
