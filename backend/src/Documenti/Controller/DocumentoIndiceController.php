<?php

namespace App\Documenti\Controller;

use App\Documenti\DocumentAudit;
use App\Documenti\Entity\Documento;
use App\Documenti\Enum\OcrStatus;
use App\Documenti\Indice\DocumentiSimili;
use App\Documenti\Message\IndicizzaDocumentoMessage;
use App\Documenti\Repository\DocumentoRepository;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Indice di un documento non cifrato (dati estratti, tag, riferimenti, vettori).
 *
 *   GET  /api/tenants/{tenantId}/documenti/{id}/simili   documento:read   altri documenti simili
 *   POST /api/tenants/{tenantId}/documenti/{id}/indice   documento:write  rianalizza (202)
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DocumentoIndiceController extends AbstractController
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly DocumentoRepository $documenti,
        private readonly DocumentiSimili $simili,
        private readonly DocumentAudit $audit,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Stesso riferimento (targa, POD, polizza) o contenuto vicino. Vuoto per i
     * documenti cifrati, che non hanno indice.
     */
    #[Route('/api/tenants/{tenantId}/documenti/{id}/simili', name: 'api_documento_simili', methods: ['GET'])]
    public function simili(string $tenantId, string $id): JsonResponse
    {
        $documento = $this->load($tenantId, $id);
        $this->access->require('documento:read', $documento);

        return $this->json(array_map(static function (array $s): array {
            $d = $s['documento'];
            $c = $d->getCategoria();

            return [
                'id' => (string) $d->getId(),
                'titolo' => $d->getTitolo(),
                'categoria' => ['id' => (string) $c->getId(), 'nome' => $c->getNome(), 'icona' => $c->getIcona(), 'colore' => $c->getColore(), 'isSensibile' => $c->isSensibile()],
                'dataDocumento' => $d->getDataDocumento()?->format('Y-m-d'),
                'createdAt' => $d->getCreatedAt()->format(\DATE_ATOM),
                'motivo' => $s['motivo'],
                'riferimento' => $s['riferimento'],
            ];
        }, $this->simili->trova($documento)));
    }

    /** Rimette in coda l'analisi (es. dopo aver scaricato un modello). 409 se cifrato o senza testo. */
    #[Route('/api/tenants/{tenantId}/documenti/{id}/indice', name: 'api_documento_indice', methods: ['POST'])]
    public function rianalizza(string $tenantId, string $id): JsonResponse
    {
        $documento = $this->load($tenantId, $id);
        $this->access->require('documento:write', $documento);
        if ($documento->isCifrato()) {
            throw new ConflictHttpException('I documenti cifrati non vengono analizzati.');
        }
        if (OcrStatus::COMPLETATO !== $documento->getOcrStatus()) {
            throw new ConflictHttpException('Il testo del documento non è ancora pronto.');
        }
        $this->bus->dispatch(new IndicizzaDocumentoMessage((string) $documento->getId()));
        $this->audit->write($documento, 'documento.indice_richiesto');

        return $this->json(['stato' => 'in_coda'], Response::HTTP_ACCEPTED);
    }

    private function load(string $tenantId, string $id): Documento
    {
        $tenant = $this->tenants->findByPublicId($tenantId) ?? throw new NotFoundHttpException('Tenant non trovato.');
        // Il permesso di base sul tenant prima di rivelare se il documento esiste.
        $this->access->require('documento:read', $tenant);

        return $this->documenti->findOneInTenant($tenant, $id) ?? throw new NotFoundHttpException('Documento non trovato.');
    }
}
