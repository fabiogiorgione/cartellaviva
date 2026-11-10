<?php

namespace App\Documenti\Controller;

use App\Documenti\Indice\EmbedderInterface;
use App\Documenti\Indice\IndiceDocumenti;
use App\Documenti\Indice\StatoModelliAi;
use App\Documenti\Message\IndicizzaDocumentoMessage;
use App\Documenti\State\FiltriElenco;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\Security\TenantAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Avanzamento dell'analisi AI dei documenti della famiglia.
 *
 *   GET  /api/tenants/{tenantId}/documenti/analisi   documento:read    conteggi e stato dei modelli
 *   POST /api/tenants/{tenantId}/documenti/analisi   documento:write   rimette in coda i mancanti (202)
 *
 * Solo conteggi, nessun contenuto. Con l'accesso d'emergenza limitato contano
 * solo le categorie concesse, come nell'elenco dei documenti.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DocumentiAnalisiController extends AbstractController
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly FiltriElenco $filtriElenco,
        private readonly IndiceDocumenti $indice,
        private readonly EmbedderInterface $embedder,
        private readonly StatoModelliAi $modelli,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $audit,
    ) {
    }

    // Priorità: altrimenti "analisi" verrebbe preso per l'id di GET /documenti/{id}.
    #[Route('/api/tenants/{tenantId}/documenti/analisi', name: 'api_documenti_analisi', methods: ['GET'], priority: 1)]
    public function avanzamento(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'documento:read');
        $categorie = $this->filtriElenco->leggi($tenant, null)['categorieConsentite'];

        return $this->json($this->indice->avanzamento($tenant, $categorie, $this->modelloEmbedding()) + ['modelli' => $this->modelli->stato()]);
    }

    #[Route('/api/tenants/{tenantId}/documenti/analisi', name: 'api_documenti_analisi_mancanti', methods: ['POST'], priority: 1)]
    public function analizzaMancanti(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'documento:write');
        $categorie = $this->filtriElenco->leggi($tenant, null)['categorieConsentite'];
        $ids = $this->indice->daIndicizzare(false, $this->modelloEmbedding(), $tenant, $categorie);
        foreach ($ids as $id) {
            $this->bus->dispatch(new IndicizzaDocumentoMessage($id));
        }
        $this->audit->log('documento.analisi_richiesta', AuditOutcome::SUCCESS, permission: 'documento:write', resourceType: 'documento', tenantId: $tenant->getId(), context: ['documenti' => \count($ids)]);

        return $this->json(['inCoda' => \count($ids)], Response::HTTP_ACCEPTED);
    }

    private function modelloEmbedding(): ?string
    {
        return $this->embedder->isEnabled() ? $this->embedder->modello() : null;
    }
}
