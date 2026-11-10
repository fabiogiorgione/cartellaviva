<?php

namespace App\Documenti\Controller;

use App\Documenti\CondivisioneService;
use App\Documenti\Entity\CondivisioneEsterna;
use App\Documenti\Entity\Documento;
use App\Shared\Http\JsonBodyTrait;
use App\Shared\Http\RispostaFile;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Condivisioni esterne.
 *
 * Famiglia (autenticata):
 *   POST /api/tenants/{t}/condivisioni {documentiIds, ore, maxDownload, nota?} -> {url, codice} (il codice si vede SOLO qui)
 *   GET  /api/tenants/{t}/condivisioni
 *   POST /api/tenants/{t}/condivisioni/{id}/revoca
 *
 * Destinatario (pubblici, con rate limit):
 *   GET  /api/condivisioni/{token}                     -> anteprima senza titoli
 *   POST /api/condivisioni/{token}/accedi {codice}     -> {sessione, documenti}
 *   GET  /api/condivisioni/{token}/documenti/{id}/file (header X-Condivisione-Sessione)
 */
final class CondivisioniController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly CondivisioneService $condivisioni,
        private readonly TenantAccess $access,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.public_auth')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/condivisioni', name: 'api_condivisione_crea', methods: ['POST'])]
    public function crea(string $tenantId, Request $request): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'documento:read');
        $body = $this->body($request);
        $r = $this->condivisioni->crea(
            $tenant,
            \is_array($body['documentiIds'] ?? null) ? $body['documentiIds'] : [],
            (int) ($body['ore'] ?? 168),
            (int) ($body['maxDownload'] ?? 10),
            $this->str($body, 'nota', 120),
        );

        return $this->json(['url' => $r['url'], 'codice' => $r['codice']] + $this->vista($r['condivisione']), Response::HTTP_CREATED);
    }

    #[Route('/api/tenants/{tenantId}/condivisioni', name: 'api_condivisioni', methods: ['GET'])]
    public function elenco(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'documento:read');

        return $this->json(array_map($this->vista(...), $this->condivisioni->elenco($tenant)));
    }

    #[Route('/api/tenants/{tenantId}/condivisioni/{id}/revoca', name: 'api_condivisione_revoca', methods: ['POST'])]
    public function revoca(string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'documento:read');
        $c = Uuid::isValid($id) ? $this->em->getRepository(CondivisioneEsterna::class)->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]) : null;
        if (!$c instanceof CondivisioneEsterna) {
            throw new NotFoundHttpException('Condivisione non trovata.');
        }
        $this->condivisioni->revoca($c);

        return $this->json($this->vista($c));
    }

    #[Route('/api/condivisioni/{token}', name: 'api_condivisione_anteprima', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function anteprima(string $token, Request $request): JsonResponse
    {
        $this->limita($request);

        return $this->json($this->condivisioni->anteprima($token));
    }

    #[Route('/api/condivisioni/{token}/accedi', name: 'api_condivisione_accedi', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function accedi(string $token, Request $request): JsonResponse
    {
        $this->limita($request);
        $r = $this->condivisioni->accedi($token, (string) ($this->body($request)['codice'] ?? ''));
        $c = $r['condivisione'];

        return $this->json([
            'sessione' => $r['sessione'],
            'scadeIl' => $c->getScadeIl()->format(\DATE_ATOM),
            'downloadRimasti' => max(0, $c->getMaxDownload() - $c->getDownload()),
            'condivisoDa' => $c->getOwner()->getFullName(),
            'documenti' => array_map(static fn (Documento $d) => [
                'id' => (string) $d->getId(),
                'titolo' => $d->getTitolo(),
                'nomeFile' => $d->getNomeFileOriginale(),
                'mimeType' => $d->getMimeType(),
                'dimensioneBytes' => $d->getDimensioneBytes(),
            ], $c->getDocumenti()),
        ]);
    }

    #[Route('/api/condivisioni/{token}/documenti/{id}/file', name: 'api_condivisione_file', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function scarica(string $token, string $id, Request $request): Response
    {
        $this->limita($request);
        $r = $this->condivisioni->scarica($token, (string) $request->headers->get('X-Condivisione-Sessione', ''), $id);
        return RispostaFile::allegato($r['contenuto'], $r['documento']->getMimeType(), $r['documento']->getNomeFileOriginale());
    }

    private function limita(Request $request): void
    {
        if (!$this->limiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }
    }

    /** @return array<string, mixed> per chi ha condiviso: mai il codice né il link */
    private function vista(CondivisioneEsterna $c): array
    {
        return [
            'id' => (string) $c->getId(),
            'stato' => $c->getStato(),
            'nota' => $c->getNota(),
            'documenti' => array_map(static fn (Documento $d) => ['id' => (string) $d->getId(), 'titolo' => $d->getTitolo()], $c->getDocumenti()),
            'scadeIl' => $c->getScadeIl()->format(\DATE_ATOM),
            'maxDownload' => $c->getMaxDownload(),
            'download' => $c->getDownload(),
            'primoAccessoIl' => $c->getPrimoAccessoIl()?->format(\DATE_ATOM),
            'ultimoAccessoIl' => $c->getUltimoAccessoIl()?->format(\DATE_ATOM),
            'revocataIl' => $c->getRevocataIl()?->format(\DATE_ATOM),
            'creataDa' => $c->getOwner()->getFullName(),
            'createdAt' => $c->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
