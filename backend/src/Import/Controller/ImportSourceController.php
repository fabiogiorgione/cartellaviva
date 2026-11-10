<?php

namespace App\Import\Controller;

use App\Import\ApiResource\ImportSourceResource;
use App\Import\Entity\ImportSource;
use App\Import\Exception\ImportException;
use App\Import\ImportSourceService;
use App\Import\Repository\ImportSourceRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Collegamento OAuth e sincronizzazione manuale di una sorgente di import.
 *
 * Il collegamento è in due passi perché un redirect del browser non porta il JWT:
 * 1. POST .../connect (autenticato, import:manage) -> URL di Google da aprire;
 * 2. GET /api/import-sources/oauth/callback (PUBBLICO, un solo URL fisso da
 *    registrare in Google = GOOGLE_OAUTH_REDIRECT_URI): vale solo con lo state
 *    monouso emesso al passo 1; alla fine rimanda al frontend.
 */
final class ImportSourceController extends AbstractController
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly ImportSourceRepository $sources,
        private readonly ImportSourceService $service,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
        #[Autowire('%app.mobile_return_url%')]
        private readonly string $mobileReturnUrl,
    ) {
    }

    /** Corpo facoltativo: {"ritorno": "web"|"app"} (default web). */
    #[Route('/api/tenants/{tenantId}/import-sources/{id}/connect', name: 'api_import_source_connect', methods: ['POST'])]
    public function connect(string $tenantId, string $id, Request $request): Response
    {
        $source = $this->load($tenantId, $id);
        $body = '' !== $request->getContent() ? json_decode($request->getContent(), true) : [];
        $ritorno = \is_array($body) && \is_string($body['ritorno'] ?? null) ? $body['ritorno'] : ImportSourceService::RITORNO_WEB;

        return $this->json(['authorizationUrl' => $this->service->startConnect($source, $this->access->currentUser(), $ritorno)]);
    }

    /** Solo manuale: mette in coda ImportJobMessage (202). */
    #[Route('/api/tenants/{tenantId}/import-sources/{id}/sync', name: 'api_import_source_sync', methods: ['POST'])]
    public function sync(string $tenantId, string $id): Response
    {
        $source = $this->load($tenantId, $id);
        $this->service->requestSync($source);

        return $this->json(ImportSourceResource::fromEntity($source), Response::HTTP_ACCEPTED);
    }

    #[Route('/api/import-sources/oauth/callback', name: 'api_import_source_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $state = (string) $request->query->get('state', '');
        $base = ImportSourceService::RITORNO_APP === $this->service->ritornoForState($state)
            ? $this->mobileReturnUrl
            : rtrim($this->frontendUrl, '/').'/import/esito';
        try {
            $source = $this->service->completeConnect(
                $state,
                $request->query->has('code') ? (string) $request->query->get('code') : null,
                $request->query->has('error') ? (string) $request->query->get('error') : null,
            );
            $params = ['esito' => 'ok', 'tenant' => (string) $source->getTenant()->getId(), 'sorgente' => (string) $source->getId()];
        } catch (ImportException $e) {
            // Al browser solo un codice breve: i dettagli sono nell'audit e in ultimoErrore.
            $params = ['esito' => 'errore', 'motivo' => $e->getMessage()];
        }

        $response = new RedirectResponse($base.'?'.http_build_query($params));
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function load(string $tenantId, string $id): ImportSource
    {
        $tenant = $this->access->requireTenant($tenantId, ImportSourceService::PERMISSION);

        return $this->sources->findOneInTenant($tenant, $id) ?? throw new NotFoundHttpException('Sorgente non trovata.');
    }
}
