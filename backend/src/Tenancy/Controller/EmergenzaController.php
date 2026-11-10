<?php

namespace App\Tenancy\Controller;

use App\Account\Entity\User;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\EmergenzaService;
use App\Tenancy\Entity\ContattoEmergenza;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Accesso d'emergenza.
 *
 * Famiglia:  POST/GET /api/tenants/{t}/emergenza/contatti, POST …/{id}/revoca, POST …/{id}/nega
 * Contatto:  GET /api/emergenza/invito?token= (pubblica), POST /api/emergenza/accetta|rifiuta {token},
 *            GET /api/emergenza, POST /api/emergenza/{id}/richiedi {motivo?}
 * Link email: POST /api/emergenza/annulla {token} (pubblica: opposizione)
 */
final class EmergenzaController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly EmergenzaService $emergenza,
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.public_auth')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/emergenza/contatti', name: 'api_emergenza_invita', methods: ['POST'])]
    public function invita(string $tenantId, Request $request): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'tenant:read');
        $body = $this->body($request);
        $c = $this->emergenza->invita(
            $tenant,
            $this->access->currentUser(),
            (string) ($body['email'] ?? ''),
            \is_array($body['categorieIds'] ?? null) ? array_values($body['categorieIds']) : null,
            (int) ($body['giorniAttesa'] ?? 7),
        );

        return $this->json($this->vista($c), Response::HTTP_CREATED);
    }

    /** Proprietari e membri vedono i contatti (per potersi opporre); solo i proprietari li gestiscono. */
    #[Route('/api/tenants/{tenantId}/emergenza/contatti', name: 'api_emergenza_contatti', methods: ['GET'])]
    public function contatti(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'membro:read');

        return $this->json(array_map($this->vista(...), $this->emergenza->contatti($tenant)));
    }

    #[Route('/api/tenants/{tenantId}/emergenza/contatti/{id}/revoca', name: 'api_emergenza_revoca', methods: ['POST'])]
    public function revoca(string $tenantId, string $id): JsonResponse
    {
        $c = $this->contatto($tenantId, $id);
        $this->emergenza->revoca($c, $this->access->currentUser());

        return $this->json($this->vista($c));
    }

    #[Route('/api/tenants/{tenantId}/emergenza/contatti/{id}/nega', name: 'api_emergenza_nega', methods: ['POST'])]
    public function nega(string $tenantId, string $id): JsonResponse
    {
        $c = $this->contatto($tenantId, $id);
        $this->emergenza->nega($c, $this->access->currentUser());

        return $this->json($this->vista($c));
    }

    #[Route('/api/emergenza/invito', name: 'api_emergenza_invito', methods: ['GET'])]
    public function invito(Request $request): JsonResponse
    {
        $this->limita($request);

        return $this->json($this->emergenza->anteprimaInvito((string) $request->query->get('token', '')));
    }

    #[Route('/api/emergenza/accetta', name: 'api_emergenza_accetta', methods: ['POST'])]
    public function accetta(Request $request): JsonResponse
    {
        $c = $this->emergenza->accetta((string) ($this->body($request)['token'] ?? ''), $this->utente());

        return $this->json($this->vistaContatto($c));
    }

    #[Route('/api/emergenza/rifiuta', name: 'api_emergenza_rifiuta', methods: ['POST'])]
    public function rifiuta(Request $request): Response
    {
        $this->emergenza->rifiuta((string) ($this->body($request)['token'] ?? ''), $this->utente());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/emergenza', name: 'api_emergenza_miei', methods: ['GET'])]
    public function miei(): JsonResponse
    {
        return $this->json(array_map($this->vistaContatto(...), $this->emergenza->miei($this->utente())));
    }

    #[Route('/api/emergenza/{id}/richiedi', name: 'api_emergenza_richiedi', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function richiedi(string $id, Request $request): JsonResponse
    {
        $c = $this->em->find(ContattoEmergenza::class, Uuid::fromString($id)) ?? throw new NotFoundHttpException('Contatto non trovato.');
        $this->emergenza->richiedi($c, $this->utente(), $this->str($this->body($request), 'motivo', 500));

        return $this->json($this->vistaContatto($c));
    }

    #[Route('/api/emergenza/annulla', name: 'api_emergenza_annulla', methods: ['POST'])]
    public function annulla(Request $request): JsonResponse
    {
        $this->limita($request);
        $c = $this->emergenza->negaDaLink((string) ($this->body($request)['token'] ?? ''));

        return $this->json(['messaggio' => sprintf('Ti sei opposto: l\'accesso d\'emergenza alla famiglia "%s" non verrà concesso.', $c->getTenant()->getName())]);
    }

    private function contatto(string $tenantId, string $id): ContattoEmergenza
    {
        $tenant = $this->access->requireTenant($tenantId, 'tenant:read');
        $c = Uuid::isValid($id) ? $this->em->getRepository(ContattoEmergenza::class)->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]) : null;

        return $c instanceof ContattoEmergenza ? $c : throw new NotFoundHttpException('Contatto non trovato.');
    }

    private function utente(): User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : throw new AccessDeniedHttpException('Serve un account.');
    }

    private function limita(Request $request): void
    {
        if (!$this->limiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }
    }

    /** @return array<string, mixed> vista per la famiglia */
    private function vista(ContattoEmergenza $c): array
    {
        return [
            'id' => (string) $c->getId(),
            'email' => $c->getEmail(),
            'nome' => $c->getContatto()?->getFullName(),
            'stato' => $c->getStato(),
            'categorie' => $c->getCategorie(),
            'giorniAttesa' => $c->getGiorniAttesa(),
            'richiestaStato' => $c->getRichiestaStato(),
            'richiestaIl' => $c->getRichiestaIl()?->format(\DATE_ATOM),
            'eseguibileIl' => $c->getEseguibileIl()?->format(\DATE_ATOM),
            'motivo' => $c->getMotivo(),
            'configuratoDa' => $c->getConfiguratoDa()?->getFullName(),
        ];
    }

    /** @return array<string, mixed> vista per il contatto: niente sui documenti */
    private function vistaContatto(ContattoEmergenza $c): array
    {
        return [
            'id' => (string) $c->getId(),
            'famiglia' => $c->getTenant()->getName(),
            'tenantId' => (string) $c->getTenant()->getId(),
            'giorniAttesa' => $c->getGiorniAttesa(),
            'richiestaStato' => $c->getRichiestaStato(),
            'eseguibileIl' => $c->getEseguibileIl()?->format(\DATE_ATOM),
            'puoChiedere' => $c->puoChiedere(),
        ];
    }
}
