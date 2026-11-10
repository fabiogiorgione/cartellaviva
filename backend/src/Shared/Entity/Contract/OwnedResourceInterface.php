<?php

namespace App\Shared\Entity\Contract;

use App\Account\Entity\User;

/**
 * Risorsa applicativa con proprietario tracciato (tenant_id + owner_user_id).
 *
 * Tutte le risorse future (Documento, Scadenza, ...) devono implementare questa
 * interfaccia usando TenantOwnedTrait: i permessi con suffisso ":own" vengono
 * concessi solo se l'utente corrente è il proprietario della risorsa.
 */
interface OwnedResourceInterface extends TenantScopedInterface
{
    public function getOwner(): User;
}
