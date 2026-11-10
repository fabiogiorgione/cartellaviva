<?php

namespace App\Tenancy\Security;

use App\Account\Entity\User;
use App\Documenti\Entity\Documento;
use App\Shared\Audit\AuditLogger;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\PermissionCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Voter unico per tutti i permessi "risorsa:azione".
 *
 * Uso: $this->isGranted('membro:invite', $tenant) oppure
 *      $this->isGranted('documento:write', $documento)
 *
 * Il soggetto è OBBLIGATORIO e deve essere un Tenant o una risorsa
 * TenantScopedInterface: non esistono permessi globali. Passi della decisione:
 *   1. utente autenticato e attivo;
 *   2. Membership ATTIVA sul tenant della risorsa (altrimenti negato sempre);
 *   3. il ruolo della Membership ha il permesso (codice esatto o variante ":all");
 *   4. con la sola variante ":own" serve che l'utente sia il proprietario
 *      (owner_user_id) della risorsa: su un Tenant ":own" non basta.
 *
 * Scelte volute: nessun ripiego "se sei createdBy/updatedBy
 * passi comunque", nessun permesso globale, superadmin limitato alle risorse di
 * amministrazione (mai documenti o scadenze delle famiglie).
 *
 * Ogni decisione negata viene registrata nell'audit log; quelle concesse solo
 * per le risorse marcate "sensitive" nel catalogo.
 */
final class TenantPermissionVoter extends Voter implements ResetInterface
{
    /** Risorse che il superadmin può amministrare senza Membership (mai i dati delle famiglie). */
    public const PLATFORM_ADMIN_RESOURCES = ['tenant', 'membro', 'ruolo', 'audit'];

    private const ATTRIBUTE_PATTERN = '/^[a-z][a-z_]*:[a-z_]+$/';

    /** @var array<string, bool> decisioni già prese nella richiesta (evita righe di audit duplicate) */
    private array $decided = [];

    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionResolver $resolver,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return 1 === preg_match(self::ATTRIBUTE_PATTERN, $attribute);
    }

    public function supportsType(string $subjectType): bool
    {
        return true;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Il soggetto NON è un criterio di supporto: senza soggetto votiamo "negato"
        // (e lo registriamo) invece di astenerci in silenzio.
        return $this->supportsAttribute($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $memoKey = $this->memoKey($attribute, $subject, $user);
        if (null !== $memoKey && isset($this->decided[$memoKey])) {
            return $this->decided[$memoKey];
        }

        [$granted, $reason, $tenant] = $this->decide($attribute, $subject, $user);
        $vote?->addReason($reason);
        if (null !== $memoKey) {
            $this->decided[$memoKey] = $granted;
        }

        if (!$granted || $this->catalog->isSensitive($attribute)) {
            $this->audit->log(
                'permission.check',
                $granted ? AuditOutcome::GRANTED : AuditOutcome::DENIED,
                permission: $attribute,
                resourceType: $this->resourceType($subject),
                resourceId: $this->resourceId($subject),
                tenantId: $tenant?->getId(),
                context: ['reason' => $reason],
                actor: $user instanceof User ? $user : null,
            );
        }

        return $granted;
    }

    public function reset(): void
    {
        $this->decided = [];
    }

    /**
     * @return array{0: bool, 1: string, 2: ?Tenant}
     */
    private function decide(string $attribute, mixed $subject, mixed $user): array
    {
        if (!$user instanceof User) {
            return [false, 'not_authenticated', null];
        }
        if (!$user->isActive()) {
            return [false, 'user_inactive', null];
        }
        if (!$this->catalog->isCheckableAttribute($attribute)) {
            $this->logger->warning('Permesso sconosciuto richiesto: {attribute}', ['attribute' => $attribute]);

            return [false, 'unknown_permission', null];
        }

        $tenant = match (true) {
            $subject instanceof Tenant => $subject,
            $subject instanceof TenantScopedInterface => $subject->getTenant(),
            default => null,
        };
        if (null === $tenant) {
            return [false, 'missing_tenant_subject', null];
        }

        $resource = explode(':', $attribute, 2)[0];
        if ($user->isSuperAdmin() && \in_array($resource, self::PLATFORM_ADMIN_RESOURCES, true)) {
            return [true, 'platform_superadmin', $tenant];
        }

        $membership = $this->resolver->getActiveMembership($user, $tenant);
        if (null === $membership) {
            return [false, 'no_active_membership', $tenant];
        }
        // Accesso d'emergenza limitato ad alcune categorie: gli altri documenti non esistono per lui.
        if ($subject instanceof Documento && !$membership->consenteCategoria($subject->getCategoria()->getId())) {
            return [false, 'categoria_non_consentita', $tenant];
        }

        // Nei giorni di ripensamento prima della cancellazione si può solo leggere
        // (e scaricare/esportare): l'annullamento passa da un controllo dedicato.
        if ($tenant->isInEliminazione() && 'read' !== explode(':', $attribute)[1]) {
            return [false, 'tenant_in_eliminazione', $tenant];
        }

        $codes = $this->resolver->getPermissionCodes($user, $tenant);
        if (\in_array($attribute, $codes, true) || \in_array($attribute.':all', $codes, true)) {
            return [true, 'role_permission', $tenant];
        }

        if (\in_array($attribute.':own', $codes, true)) {
            if ($subject instanceof OwnedResourceInterface && $subject->getOwner()->getId()->equals($user->getId())) {
                return [true, 'role_permission_own', $tenant];
            }

            return [false, $subject instanceof OwnedResourceInterface ? 'not_resource_owner' : 'own_scope_requires_resource', $tenant];
        }

        return [false, 'permission_missing', $tenant];
    }

    /** Memoizza solo soggetti con identità stabile (classe + id), mai per identità d'oggetto. */
    private function memoKey(string $attribute, mixed $subject, mixed $user): ?string
    {
        $resourceId = $this->resourceId($subject);
        if (!\is_object($subject) || null === $resourceId || !$user instanceof User) {
            return null;
        }

        return implode('|', [$attribute, $subject::class, $resourceId, $user->getId()->toRfc4122()]);
    }

    private function resourceType(mixed $subject): ?string
    {
        if (!\is_object($subject)) {
            return null;
        }
        $short = (new \ReflectionClass($subject))->getShortName();

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    private function resourceId(mixed $subject): ?string
    {
        if (\is_object($subject) && method_exists($subject, 'getId')) {
            $id = $subject->getId();

            return null === $id ? null : (string) $id;
        }

        return null;
    }
}
