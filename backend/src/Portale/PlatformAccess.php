<?php

namespace App\Portale;

use App\Account\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\NonConsentito;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Accesso al portale per livello:
 * - VEDERE  (superadmin, supporto, sola lettura): salute del sistema, registro globale;
 * - SUPPORTO (superadmin, supporto): tenant e utenti (solo metadati), recuperi
 *   d'accesso, nuovo proprietario, preparazione della verifica degli enti;
 * - ADMIN   (solo superadmin): approvare enti, team del portale, configurazione.
 *
 * Nessun livello apre documenti: il portale non ha endpoint sui contenuti.
 */
final class PlatformAccess
{
    public const VEDERE = 'vedere';
    public const SUPPORTO = 'supporto';
    public const ADMIN = 'admin';

    private const LIVELLI = [
        self::VEDERE => [User::PLATFORM_SUPERADMIN, User::PLATFORM_SUPPORTO, User::PLATFORM_SOLA_LETTURA],
        self::SUPPORTO => [User::PLATFORM_SUPERADMIN, User::PLATFORM_SUPPORTO],
        self::ADMIN => [User::PLATFORM_SUPERADMIN],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly AuditLogger $audit,
    ) {
    }

    public function require(string $livello): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->has($user, $livello)) {
            $this->audit->log('portale.accesso', AuditOutcome::DENIED, context: ['livello' => $livello], actor: $user instanceof User ? $user : null);
            throw new NonConsentito(self::ADMIN === $livello ? 'Riservato al superadmin.' : 'Riservato al team del portale.');
        }

        return $user;
    }

    public function has(User $user, string $livello): bool
    {
        return $user->isActive() && \in_array($user->getPlatformRole(), self::LIVELLI[$livello], true);
    }
}
