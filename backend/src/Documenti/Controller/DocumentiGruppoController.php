<?php

namespace App\Documenti\Controller;

use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Repository\CategoriaRepository;
use App\Documenti\Repository\DocumentoRepository;
use App\Shared\Errore\NonConsentito;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Operazioni di gruppo sui documenti selezionati nella lista:
 *   POST /api/tenants/{tenantId}/documenti/gruppo
 *   {"azione": "sposta", "ids": [...], "categoriaId": "..."} | {"azione": "elimina", "ids": [...]}
 *
 * Ogni documento passa dagli stessi controlli dell'operazione singola
 * (documento:write / documento:delete, anche per proprietario): chi non si
 * può toccare viene saltato con il motivo, gli altri vanno avanti.
 * Spostare verso una categoria sensibile cifra il documento (come il PATCH).
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DocumentiGruppoController extends AbstractController
{
    use JsonBodyTrait;

    private const MAX = 200;

    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly DocumentoRepository $documenti,
        private readonly CategoriaRepository $categorie,
        private readonly DocumentService $documents,
        private readonly DocumentAudit $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/documenti/gruppo', name: 'api_documenti_gruppo', methods: ['POST'])]
    public function __invoke(string $tenantId, Request $request): JsonResponse
    {
        $tenant = $this->tenants->findByPublicId($tenantId) ?? throw new NotFoundHttpException('Tenant non trovato.');
        $this->access->require('documento:read', $tenant);
        $body = $this->body($request);
        $azione = (string) ($body['azione'] ?? '');
        if (!\in_array($azione, ['sposta', 'elimina'], true)) {
            throw new UnprocessableEntityHttpException('azione: sposta o elimina.');
        }
        $ids = \is_array($body['ids'] ?? null) ? array_values(array_unique(array_filter($body['ids'], 'is_string'))) : [];
        if ([] === $ids || \count($ids) > self::MAX) {
            throw new UnprocessableEntityHttpException(sprintf('ids: da 1 a %d documenti.', self::MAX));
        }
        $categoria = null;
        if ('sposta' === $azione) {
            $categoria = $this->categorie->findUsableById($tenant, (string) ($body['categoriaId'] ?? ''))
                ?? throw new UnprocessableEntityHttpException('categoriaId: scegli una categoria della famiglia.');
        }

        $fatti = 0;
        $saltati = [];
        foreach ($ids as $id) {
            $documento = $this->documenti->findOneInTenant($tenant, $id);
            // Chi non può leggerlo (es. emergenza limitata ad altre categorie) non ne vede nemmeno il titolo.
            if (null === $documento || !$this->access->isGranted('documento:read', $documento)) {
                $saltati[] = ['id' => $id, 'titolo' => null, 'motivo' => 'documento non trovato'];
                continue;
            }
            try {
                if (null !== $categoria) {
                    $this->access->require('documento:write', $documento);
                    if ($documento->getCategoria()->getId()->equals($categoria->getId())) {
                        $saltati[] = ['id' => $id, 'titolo' => $documento->getTitolo(), 'motivo' => 'è già in questa categoria'];
                        continue;
                    }
                    $this->documents->changeCategoria($documento, $categoria);
                    $this->em->flush();
                    $this->audit->write($documento, 'documento.updated', ['campi' => ['categoria'], 'gruppo' => true]);
                } else {
                    $this->access->require('documento:delete', $documento);
                    $this->documents->delete($documento);
                }
                ++$fatti;
            } catch (NonConsentito) {
                $saltati[] = ['id' => $id, 'titolo' => $documento->getTitolo(), 'motivo' => 'non hai il permesso'];
            }
        }

        return $this->json(['fatti' => $fatti, 'saltati' => $saltati]);
    }
}
