<?php

namespace App\ConnettoreAi\Controller;

use App\ConnettoreAi\ConnettoriAi;
use App\ConnettoreAi\Repository\ConnettoreAiRepository;
use App\Shared\Errore\NonTrovato;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * I Mac collegati alla famiglia, dall'app (firewall "api", utente):
 *   GET    /api/tenants/{t}/connettori-ai                elenco
 *   GET    /api/tenants/{t}/connettori-ai/richiesta?codice=…  la richiesta da approvare
 *   POST   /api/tenants/{t}/connettori-ai/approva        {userCode, nome?, codice2fa? | password?}
 *   POST   /api/tenants/{t}/connettori-ai/nega           {userCode}
 *   PATCH  /api/tenants/{t}/connettori-ai/{id}           {nome}
 *   DELETE /api/tenants/{t}/connettori-ai/{id}           scollega
 * Tutto con connettore_ai:manage.
 */
final class ConnettoriAiController extends AbstractController
{
    use JsonBodyTrait;

    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly TenantAccess $access,
        private readonly ConnettoriAi $connettori,
        private readonly ConnettoreAiRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/connettori-ai', name: 'api_connettori_ai', methods: ['GET'])]
    public function elenco(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, ConnettoriAi::PERMESSO);
        $ora = new \DateTimeImmutable();

        return $this->json(['connettori' => array_map(static fn ($c) => $c->vista($ora), $this->repo->attivi($tenant))]);
    }

    #[Route('/api/tenants/{tenantId}/connettori-ai/richiesta', name: 'api_connettori_ai_richiesta', methods: ['GET'], priority: 1)]
    public function richiesta(Request $request, string $tenantId): JsonResponse
    {
        $this->access->requireTenant($tenantId, ConnettoriAi::PERMESSO);
        $r = $this->connettori->richiesta((string) $request->query->get('codice', ''));

        return $this->json(['userCode' => $r->getUserCode(), 'nome' => $r->getNomeProposto(), 'scadeIl' => $r->getScadeIl()->format(\DATE_ATOM)]);
    }

    #[Route('/api/tenants/{tenantId}/connettori-ai/approva', name: 'api_connettori_ai_approva', methods: ['POST'], priority: 1)]
    public function approva(Request $request, string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, ConnettoriAi::PERMESSO);
        $d = $this->body($request);
        $this->connettori->approva($tenant, $this->access->currentUser(), (string) ($d['userCode'] ?? ''), $this->str($d, 'nome', 80), $this->str($d, 'codice2fa', 20), \is_string($d['password'] ?? null) ? $d['password'] : null);

        return $this->json(['ok' => true]);
    }

    #[Route('/api/tenants/{tenantId}/connettori-ai/nega', name: 'api_connettori_ai_nega', methods: ['POST'], priority: 1)]
    public function nega(Request $request, string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, ConnettoriAi::PERMESSO);
        $this->connettori->nega($tenant, (string) ($this->body($request)['userCode'] ?? ''));

        return $this->json(['ok' => true]);
    }

    #[Route('/api/tenants/{tenantId}/connettori-ai/{id}', name: 'api_connettori_ai_rinomina', methods: ['PATCH'], requirements: self::ID)]
    public function rinomina(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, ConnettoriAi::PERMESSO);
        $c = $this->repo->find($id);
        if (null === $c || $c->getTenant() !== $tenant || $c->isRevocato()) {
            throw new NonTrovato('Mac non trovato.');
        }
        $c->rinomina(ConnettoriAi::nome((string) ($this->body($request)['nome'] ?? '')));
        $this->em->flush();

        return $this->json($c->vista(new \DateTimeImmutable()));
    }

    #[Route('/api/tenants/{tenantId}/connettori-ai/{id}', name: 'api_connettori_ai_scollega', methods: ['DELETE'], requirements: self::ID)]
    public function scollega(string $tenantId, string $id): Response
    {
        $tenant = $this->access->requireTenant($tenantId, ConnettoriAi::PERMESSO);
        $c = $this->repo->find($id);
        if (null === $c || $c->getTenant() !== $tenant) {
            throw new NonTrovato('Mac non trovato.');
        }
        $this->connettori->revoca($c);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
