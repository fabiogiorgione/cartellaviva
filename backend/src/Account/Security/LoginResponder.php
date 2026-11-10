<?php

namespace App\Account\Security;

use App\Account\Entity\User;
use App\Account\TwoFactor\TwoFactorService;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unico punto in cui, superato il primo fattore (password o Google), si decide:
 * - 2FA attiva  -> 200 {twoFactorRequired: true, challenge, methods}
 * - 2FA assente -> token JWT + refresh token (handler di Lexik, a cui Gesdinet
 *   aggiunge il refresh token).
 */
final class LoginResponder
{
    public function __construct(
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        private readonly AuthenticationSuccessHandler $lexikSuccessHandler,
        private readonly TwoFactorService $twoFactor,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string, mixed> $extra dati aggiunti alla risposta (es. isNewUser) */
    public function respond(User $user, string $method, array $extra = []): Response
    {
        if ($user->isTwoFactorEnabled()) {
            $challenge = $this->twoFactor->createChallenge($user, $method);
            $this->audit->log('auth.2fa_challenge', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['method' => $method], actor: $user);

            return new JsonResponse([
                'twoFactorRequired' => true,
                'challenge' => $challenge,
                'methods' => ['totp', 'backup_code'],
            ] + $extra);
        }

        return $this->issueTokens($user, $extra);
    }

    /** @param array<string, mixed> $extra */
    public function issueTokens(User $user, array $extra = []): Response
    {
        $user->markLoggedIn();
        $this->em->flush();

        $response = $this->lexikSuccessHandler->handleAuthenticationSuccess($user);
        if ([] !== $extra && $response instanceof JsonResponse) {
            $data = json_decode((string) $response->getContent(), true);
            $response->setData($extra + (\is_array($data) ? $data : []));
        }

        return $response;
    }
}
