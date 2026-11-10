<?php

namespace App\Ente\Controller;

use App\Account\Entity\User;
use App\Ente\CollegamentoService;
use App\Ente\Entity\Collegamento;
use App\Shared\Doctrine\PerId;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lato famiglia del collegamento con un ente.
 *
 * Anteprima, rifiuto e "non sono io" sono PUBBLICI (il token dell'email fa da
 * credenziale: chi non ha un account deve poter dire "non sono io" senza
 * registrarsi). Accettare richiede l'accesso con l'account invitato.
 */
final class CollegamentiController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly CollegamentoService $service,
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/collegamenti/anteprima', name: 'api_collegamento_anteprima', methods: ['GET'])]
    public function anteprima(Request $request): JsonResponse
    {
        return $this->json($this->service->anteprima($this->service->daToken((string) $request->query->get('token', ''))));
    }

    /** Corpo: {token, tenantId, personaId? | nuovaPersona?: {nome, cognome}}. */
    #[Route('/api/collegamenti/accetta', name: 'api_collegamento_accetta', methods: ['POST'])]
    public function accetta(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $referente = $this->service->daToken((string) ($body['token'] ?? ''));
        $famiglia = $this->tenants->findByPublicId((string) ($body['tenantId'] ?? '')) ?? throw new NotFoundHttpException('Famiglia non trovata.');
        $collegamento = $this->service->accetta(
            $referente,
            $this->access->currentUser(),
            $famiglia,
            $this->str($body, 'personaId', 36),
            \is_array($body['nuovaPersona'] ?? null) ? $body['nuovaPersona'] : null,
        );

        return $this->json($this->vista($collegamento), Response::HTTP_CREATED);
    }

    #[Route('/api/collegamenti/rifiuta', name: 'api_collegamento_rifiuta', methods: ['POST'])]
    public function rifiuta(Request $request): Response
    {
        $body = $this->body($request);
        $user = $this->getUser();
        $this->service->rifiuta($this->service->daToken((string) ($body['token'] ?? '')), true === ($body['nonSonoIo'] ?? false), $user instanceof User ? $user : null);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/tenants/{tenantId}/collegamenti', name: 'api_famiglia_collegamenti', methods: ['GET'])]
    public function elenco(string $tenantId): JsonResponse
    {
        $famiglia = $this->access->requireTenant($tenantId, 'collegamento:read');
        $collegamenti = $this->em->getRepository(Collegamento::class)->findBy(['famiglia' => $famiglia], ['createdAt' => 'DESC']);

        return $this->json(array_map($this->vista(...), $collegamenti));
    }

    #[Route('/api/tenants/{tenantId}/collegamenti/{id}', name: 'api_famiglia_collegamento_revoca', methods: ['DELETE'])]
    public function revoca(string $tenantId, string $id): Response
    {
        $famiglia = $this->access->requireTenant($tenantId, 'collegamento:manage');
        $c = PerId::trova($this->em, Collegamento::class, $id);
        if (!$c instanceof Collegamento || !$c->getFamiglia()->getId()->equals($famiglia->getId())) {
            throw new NotFoundHttpException('Collegamento non trovato.');
        }
        $this->access->require('collegamento:manage', $c);
        $this->service->revoca($c, $this->access->currentUser());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> vista della famiglia */
    private function vista(Collegamento $c): array
    {
        $r = $c->getReferente();

        return [
            'id' => (string) $c->getId(),
            'ente' => ['id' => (string) $c->getEnte()->getId(), 'nome' => $c->getEnte()->getName()],
            'iscritto' => $r->getIscritto()->getNomeCompleto(),
            'referente' => $r->getNomeCompleto(),
            'persona' => null !== $c->getPersona() ? ['id' => (string) $c->getPersona()->getId(), 'nome' => $c->getPersona()->getDisplayName()] : null,
            'attivo' => $c->isAttivo(),
            'accettatoIl' => $c->getCreatedAt()->format(\DATE_ATOM),
            'revocatoIl' => $c->getRevocatoIl()?->format(\DATE_ATOM),
        ];
    }
}
