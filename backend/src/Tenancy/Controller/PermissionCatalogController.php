<?php

namespace App\Tenancy\Controller;

use App\Tenancy\Permission\PermissionCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Catalogo dei permessi disponibili (per l'editor dei ruoli personalizzati).
 */
final class PermissionCatalogController extends AbstractController
{
    #[Route('/api/permissions', name: 'api_permission_catalog', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(PermissionCatalog $catalog): JsonResponse
    {
        return $this->json(array_values($catalog->all()));
    }
}
