<?php

namespace App\Documenti\Controller;

use App\Documenti\Entity\Documento;
use App\Documenti\Indice\FaccetteDocumenti;
use App\Documenti\Indice\RicercaPerSignificato;
use App\Documenti\State\FiltriElenco;
use App\Tenancy\Security\TenantAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/tenants/{tenantId}/documenti/faccette   documento:read
 *
 * Conteggi per il pannello dei filtri e la vista a gruppi. Accetta gli stessi
 * parametri di ricerca e di filtro della lista.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DocumentiFaccetteController extends AbstractController
{
    private const MAX_SIGNIFICATO = 100;

    public function __construct(
        private readonly TenantAccess $access,
        private readonly FiltriElenco $filtriElenco,
        private readonly FaccetteDocumenti $faccette,
        private readonly RicercaPerSignificato $ricerca,
    ) {
    }

    // Priorità: altrimenti "faccette" verrebbe preso per l'id di GET /documenti/{id}.
    #[Route('/api/tenants/{tenantId}/documenti/faccette', name: 'api_documenti_faccette', methods: ['GET'], priority: 1)]
    public function __invoke(string $tenantId, Request $request): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'documento:read');
        $filtri = $this->filtriElenco->leggi($tenant, $request);
        $q = $request->query->get('q');
        $trovati = 'significato' === $request->query->get('modo') && \is_string($q)
            ? $this->ricerca->cerca($tenant, $q, ['categorieConsentite' => $filtri['categorieConsentite']], self::MAX_SIGNIFICATO)
            : null;
        // I risultati della ricerca per significato, oppure la ricerca per parole come nella lista.
        $filtri += null === $trovati ? ['q' => $q] : ['ids' => array_map(static fn (Documento $d) => (string) $d->getId(), $trovati)];

        return $this->json($this->faccette->calcola($tenant, $filtri));
    }
}
