<?php

namespace App\Shared\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portale\PlatformAccess;
use App\Shared\ApiResource\AuditLogResource;
use App\Shared\Audit\AuditLogger;
use App\Shared\Repository\AuditLogRepository;
use App\Tenancy\Security\TenantAccess;

/**
 * @implements ProviderInterface<AuditLogResource>
 */
final class AuditLogProvider implements ProviderInterface
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly TenantAccess $access,
        private readonly AuditLogRepository $logs,
        private readonly AuditLogger $audit,
        private readonly PlatformAccess $platform,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $context['request'] ?? null;
        $limit = max(1, min(self::MAX_LIMIT, (int) ($request?->query->get('limit') ?? self::DEFAULT_LIMIT)));
        $before = $request?->query->get('before');

        // Rotta tenant: audit:read sul tenant. Rotta admin: solo superadmin.
        $tenantId = null;
        if (isset($uriVariables['tenantId'])) {
            $tenantId = $this->access->requireTenant((string) $uriVariables['tenantId'], 'audit:read')->getId();
        } else {
            // Registro globale: tutto il team del portale (anche in sola lettura).
            $this->platform->require(PlatformAccess::VEDERE);
        }

        $outcome = $request?->query->get('outcome');
        $action = $request?->query->get('action');

        $attore = $request?->query->get('attore');

        return array_map(AuditLogResource::fromEntity(...), $this->logs->findLatest(
            $tenantId,
            $limit,
            \is_string($before) ? $before : null,
            \is_string($outcome) ? $outcome : null,
            \is_string($action) ? $action : null,
            (bool) $request?->query->getBoolean('attivita'),
            \is_string($attore) ? $attore : null,
        ));
    }
}
