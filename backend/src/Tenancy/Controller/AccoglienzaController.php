<?php

namespace App\Tenancy\Controller;

use App\Account\Entity\User;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\PrimiPassi;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accoglienza (fase 8):
 *   GET  /api/tenants/{t}/primi-passi            -> {tipo, passi: [{id, fatto}], nascosto}
 *   POST /api/tenants/{t}/primi-passi {nascosto} -> nasconde/mostra il riquadro (per utente)
 *   POST /api/me/intro-vista                     -> l'introduzione non si ripropone
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AccoglienzaController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly TenantAccess $access,
        private readonly PrimiPassi $primiPassi,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/primi-passi', name: 'api_primi_passi', methods: ['GET'])]
    public function primiPassi(string $tenantId, #[CurrentUser] User $user): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'tenant:read');

        return $this->json($this->primiPassi->per($tenant, $user) + ['nascosto' => $user->isPrimiPassiNascosti((string) $tenant->getId())]);
    }

    #[Route('/api/tenants/{tenantId}/primi-passi', name: 'api_primi_passi_nascondi', methods: ['POST'])]
    public function nascondi(string $tenantId, #[CurrentUser] User $user, Request $request): Response
    {
        $tenant = $this->access->requireTenant($tenantId, 'tenant:read');
        $user->nascondiPrimiPassi((string) $tenant->getId(), false !== ($this->body($request)['nascosto'] ?? true));
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/me/intro-vista', name: 'api_me_intro_vista', methods: ['POST'])]
    public function introVista(#[CurrentUser] User $user): Response
    {
        $user->segnaIntroVista();
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
