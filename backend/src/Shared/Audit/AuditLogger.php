<?php

namespace App\Shared\Audit;

use App\Account\Entity\User;
use App\Shared\Enum\AuditOutcome;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Scrive una riga di audit_log con un INSERT DBAL immediato: non dipende da un
 * flush dell'ORM, quindi viene registrato anche se la richiesta poi fallisce
 * (es. accesso negato -> eccezione -> nessun flush).
 */
class AuditLogger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(
        string $action,
        AuditOutcome $outcome,
        ?string $permission = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        Uuid|string|null $tenantId = null,
        array $context = [],
        ?User $actor = null,
        ?string $actorEmail = null,
    ): void {
        $actor ??= $this->currentUser();
        $request = $this->requestStack->getMainRequest();

        try {
            $this->connection->insert('audit_log', [
                'occurred_at' => new \DateTimeImmutable(),
                'actor_id' => $actor?->getId()->toRfc4122(),
                'actor_email' => $actor?->getEmail() ?? $actorEmail,
                'tenant_id' => $tenantId instanceof Uuid ? $tenantId->toRfc4122() : $tenantId,
                'action' => $action,
                'permission' => $permission,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'outcome' => $outcome->value,
                'ip' => $request?->getClientIp(),
                'user_agent' => null !== $request ? mb_substr((string) $request->headers->get('User-Agent'), 0, 255) : null,
                'context' => $context,
            ], [
                'occurred_at' => Types::DATETIME_IMMUTABLE,
                'context' => Types::JSON,
            ]);
        } catch (\Throwable $e) {
            // L'audit non deve mai trasformare un 403 in un 500, ma il fallimento va segnalato.
            $this->logger->critical('Scrittura audit_log fallita', ['exception' => $e, 'action' => $action, 'outcome' => $outcome->value]);
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
