<?php

namespace App\Tenancy\Controller;

use App\Shared\Http\RispostaFile;
use App\Tenancy\Security\TenantAccess;
use App\Tenancy\TenantDeletion;
use App\Tenancy\TenantExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Dati" della famiglia: esportazione completa e cancellazione con ripensamento.
 * Tutte le operazioni sono riservate ai proprietari (verifica nei servizi).
 */
final class TenantDatiController extends AbstractController
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantExporter $exporter,
        private readonly TenantDeletion $deletion,
    ) {
    }

    /** Prepara il link di download (24 ore, monouso). */
    #[Route('/api/tenants/{tenantId}/esportazioni', name: 'api_tenant_export_create', methods: ['POST'])]
    public function createExport(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'tenant:read');
        ['token' => $token, 'scadeIl' => $scadeIl] = $this->exporter->create($tenant, $this->access->currentUser());

        return $this->json(['downloadPath' => '/api/esportazioni/'.$token, 'scadeIl' => $scadeIl->format(\DATE_ATOM)], Response::HTTP_CREATED);
    }

    /**
     * PUBBLICO: il token fa da credenziale (così il browser scarica direttamente,
     * senza tenere lo ZIP in memoria). Monouso, entro 24 ore.
     */
    #[Route('/api/esportazioni/{token}', name: 'api_tenant_export_download', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function download(string $token): Response
    {
        $export = $this->exporter->redeem($token);
        $tenant = $export->getTenant();

        $response = new StreamedResponse(function () use ($tenant): void {
            $this->exporter->stream($tenant, fopen('php://output', 'w'));
        });
        RispostaFile::intestazioni($response, 'application/zip', $this->exporter->fileName($tenant));
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /** Corpo: {"conferma": "<nome della famiglia>", "codice": "<codice 2FA>"}. */
    #[Route('/api/tenants/{tenantId}/eliminazione', name: 'api_tenant_deletion_request', methods: ['POST'])]
    public function requestDeletion(string $tenantId, Request $request): Response
    {
        $tenant = $this->access->requireTenant($tenantId, 'tenant:read');
        $body = json_decode($request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $this->deletion->request($tenant, $this->access->currentUser(), (string) ($body['conferma'] ?? ''), (string) ($body['codice'] ?? ''));

        return $this->json(['eliminazioneProgrammataIl' => $tenant->getEliminazioneProgrammataIl()?->format(\DATE_ATOM)]);
    }

    #[Route('/api/tenants/{tenantId}/eliminazione', name: 'api_tenant_deletion_cancel', methods: ['DELETE'])]
    public function cancelDeletion(string $tenantId): Response
    {
        $tenant = $this->access->requireTenant($tenantId, 'tenant:read');
        $this->deletion->cancel($tenant, $this->access->currentUser());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
