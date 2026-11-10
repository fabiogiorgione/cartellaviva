<?php

namespace App\Documenti;

use App\Documenti\Entity\Documento;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\Entity\Tenant;

/**
 * Audit dei documenti sull'AuditLog esistente:
 * - ogni LETTURA (dettaglio, download, testo OCR) di un documento cifrato;
 * - ogni SCRITTURA (creazione, modifica, cancellazione, nuovo OCR) di qualsiasi documento,
 *   con il flag "sensibile" nel contesto;
 * - l'accesso a una lista che contiene documenti sensibili (una riga per lista).
 * Le decisioni NEGATE le registra già il TenantPermissionVoter.
 */
final class DocumentAudit
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** @param array<string, mixed> $context */
    public function read(Documento $documento, string $action, array $context = []): void
    {
        if ($documento->isCifrato()) {
            $this->log($documento, $action, $context);
        }
    }

    /** @param array<string, mixed> $context */
    public function write(Documento $documento, string $action, array $context = []): void
    {
        $this->log($documento, $action, $context);
    }

    /** @param list<Documento> $documenti */
    public function list(Tenant $tenant, array $documenti): void
    {
        $sensibili = array_values(array_filter($documenti, static fn (Documento $d) => $d->isCifrato()));
        if ([] === $sensibili) {
            return;
        }
        $this->audit->log('documento.list', AuditOutcome::SUCCESS, permission: 'documento:read', resourceType: 'documento', tenantId: $tenant->getId(), context: [
            'sensibili' => array_map(static fn (Documento $d) => (string) $d->getId(), $sensibili),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function log(Documento $documento, string $action, array $context): void
    {
        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'documento', resourceId: (string) $documento->getId(), tenantId: $documento->getTenant()->getId(), context: $context + [
            'sensibile' => $documento->isCifrato(),
            'categoria' => $documento->getCategoria()->getNome(),
        ]);
    }
}
