<?php

namespace App\Ente\Controller;

use App\Account\EmailVerificata;
use App\Ente\EnteAccess;
use App\Ente\EnteJson;
use App\Ente\EnteRegistrazione;
use App\Shared\Http\JsonBodyTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registrazione dell'ente (chiunque abbia un account), conferma dell'email
 * istituzionale e profilo.
 */
final class EnteProfiloController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly EnteAccess $access,
        private readonly EnteRegistrazione $registrazione,
    ) {
    }

    #[Route('/api/enti', name: 'api_ente_registra', methods: ['POST'])]
    public function registra(Request $request): JsonResponse
    {
        EmailVerificata::richiedi($this->access->currentUser());
        $profilo = $this->registrazione->registra($this->access->currentUser(), $this->body($request));

        return $this->json(EnteJson::profilo($profilo), Response::HTTP_CREATED);
    }

    #[Route('/api/enti/{tenantId}/profilo', name: 'api_ente_profilo', methods: ['GET'])]
    public function profilo(string $tenantId): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:read');

        return $this->json(EnteJson::profilo($this->access->profilo($ente)));
    }

    #[Route('/api/enti/{tenantId}/profilo', name: 'api_ente_profilo_update', methods: ['PUT'])]
    public function aggiorna(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $profilo = $this->access->profilo($ente);
        $this->registrazione->aggiorna($profilo, $this->body($request), $this->access->currentUser());

        return $this->json(EnteJson::profilo($profilo));
    }

    #[Route('/api/enti/{tenantId}/email/codice', name: 'api_ente_email_codice', methods: ['POST'])]
    public function nuovoCodice(string $tenantId): Response
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $this->registrazione->inviaCodice($this->access->profilo($ente));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/enti/{tenantId}/email/conferma', name: 'api_ente_email_conferma', methods: ['POST'])]
    public function conferma(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $profilo = $this->access->profilo($ente);
        $this->registrazione->conferma($profilo, (string) ($this->body($request)['codice'] ?? ''), $this->access->currentUser());

        return $this->json(EnteJson::profilo($profilo));
    }
}
