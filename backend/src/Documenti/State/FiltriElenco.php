<?php

namespace App\Documenti\State;

use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filtri dell'elenco dei documenti letti dalla richiesta, uguali per la lista e
 * per le faccette. Comprendono le restrizioni dell'utente (accesso d'emergenza):
 * non dipendono da ciò che chiede il client.
 */
final class FiltriElenco
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly PermissionResolver $resolver,
    ) {
    }

    /** @return array<string, mixed> come DocumentoRepository::search (senza q) */
    public function leggi(Tenant $tenant, ?Request $request): array
    {
        $query = $request?->query;

        return [
            'categoriaId' => $query?->get('categoriaId'),
            'membroId' => $query?->get('membroId'),
            'anno' => $query?->get('anno'),
            'emittente' => $query?->get('emittente'),
            'tag' => $query?->get('tag'),
            'tipo' => $query?->get('tipo'),
            'categorieConsentite' => $this->resolver->getActiveMembership($this->access->currentUser(), $tenant)?->getCategorieConsentite(),
            'ordina' => $query?->get('ordina'),
            'verso' => $query?->get('verso'),
        ];
    }
}
