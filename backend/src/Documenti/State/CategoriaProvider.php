<?php

namespace App\Documenti\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Documenti\ApiResource\CategoriaResource;
use App\Documenti\Entity\Categoria;
use App\Documenti\Repository\CategoriaRepository;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Nell'elenco ogni categoria porta il numero di documenti, solo a chi può
 * leggere i documenti e solo per le categorie che vede (accesso d'emergenza:
 * le altre restano senza numero, per non rivelare cosa contengono).
 *
 * @implements ProviderInterface<CategoriaResource>
 */
final class CategoriaProvider implements ProviderInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly CategoriaRepository $categorie,
        private readonly PermissionResolver $resolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $tenantId = (string) $uriVariables['tenantId'];
        $tenant = $this->access->requireTenant($tenantId, 'tenant:read');

        if ($operation instanceof CollectionOperationInterface) {
            $user = $this->access->currentUser();
            $membership = $this->resolver->getActiveMembership($user, $tenant);
            $conteggi = \in_array('documento:read', $this->resolver->getPermissionCodes($user, $tenant), true) ? $this->categorie->contaDocumentiPerCategoria($tenant) : null;

            return array_map(static function (Categoria $c) use ($tenantId, $conteggi, $membership) {
                $r = CategoriaResource::fromEntity($c, $tenantId);
                if (null !== $conteggi && (null === $membership || $membership->consenteCategoria($c->getId()))) {
                    $r->documenti = $conteggi[(string) $c->getId()] ?? 0;
                }

                return $r;
            }, $this->categorie->findUsableIn($tenant));
        }

        $categoria = $this->categorie->findUsableById($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Categoria non trovata.');

        return CategoriaResource::fromEntity($categoria, $tenantId);
    }
}
