<?php

namespace App\Tenancy\Permission\Attribute;

/**
 * Dichiara un'entità come risorsa del catalogo permessi.
 *
 * Dichiara sulla classe di un'entità la risorsa di permesso e le azioni
 * disponibili; il comando app:permissions:sync le scrive nel catalogo a DB con
 * codice "risorsa:azione" (es. "membro:invite").
 *
 * Le azioni vanno sempre elencate esplicitamente: niente
 * LIST/VIEW/CREATE/EDIT/DELETE generati in automatico per ogni entità.
 * Le azioni con suffisso ":own" / ":all" definiscono lo scope (vedi voter).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PermissionResource
{
    /**
     * @param string                $resource  nome della risorsa, es. "membro"
     * @param array<string, string> $actions   azione => etichetta leggibile
     * @param bool                  $sensitive se true ogni decisione (anche concessa) finisce nell'audit log
     */
    public function __construct(
        public readonly string $resource,
        public readonly string $label,
        public readonly array $actions,
        public readonly bool $sensitive = true,
    ) {
    }
}
