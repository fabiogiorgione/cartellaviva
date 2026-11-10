<?php

namespace App\Account\Controller;

use App\Account\Google\GoogleAccountService;
use App\Account\Google\GoogleIdTokenVerifier;
use App\Account\Google\InvalidGoogleTokenException;
use App\Account\Repository\TwoFactorChallengeRepository;
use App\Account\Security\LoginResponder;
use App\Account\TwoFactor\TwoFactorService;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accesso con Google e secondo fattore (endpoint pubblici).
 *
 *   POST /api/auth/google {idToken}           -> token, oppure challenge 2FA
 *   POST /api/auth/2fa    {challenge, code}   -> token (code = TOTP o codice di recupero)
 */
#[Route('/api/auth')]
final class LoginFlowController extends AbstractController
{
    public function __construct(
        private readonly LoginResponder $responder,
        private readonly AuditLogger $audit,
        #[Autowire(service: 'limiter.public_auth')]
        private readonly RateLimiterFactoryInterface $publicAuthLimiter,
    ) {
    }

    #[Route('/google', name: 'api_login_google', methods: ['POST'])]
    public function google(Request $request, GoogleIdTokenVerifier $verifier, GoogleAccountService $accounts): Response
    {
        $this->throttle($request);
        if (!$verifier->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'Accesso con Google non configurato (GOOGLE_CLIENT_IDS).');
        }
        $idToken = $this->payload($request)['idToken'] ?? null;
        if (!\is_string($idToken) || '' === $idToken) {
            throw new BadRequestHttpException('idToken mancante.');
        }

        try {
            $identity = $verifier->verify($idToken);
        } catch (InvalidGoogleTokenException $e) {
            $this->audit->log('auth.login', AuditOutcome::FAILURE, context: ['method' => 'google', 'reason' => $e->getMessage()]);
            throw new UnauthorizedHttpException('Bearer', 'Accesso con Google non riuscito.', $e);
        }

        [$user, $isNew] = $accounts->resolve($identity);
        if (!$user->isActive()) {
            $this->audit->log('auth.login', AuditOutcome::FAILURE, context: ['method' => 'google', 'reason' => 'user_inactive'], actor: $user);
            throw new UnauthorizedHttpException('Bearer', 'Account disattivato.');
        }
        $this->audit->log('auth.login', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(),
            context: ['method' => 'google', 'second_factor' => $user->isTwoFactorEnabled() ? 'required' : 'none', 'new_user' => $isNew], actor: $user);

        return $this->responder->respond($user, 'google', ['isNewUser' => $isNew]);
    }

    #[Route('/2fa', name: 'api_login_2fa', methods: ['POST'])]
    public function secondFactor(Request $request, TwoFactorChallengeRepository $challenges, TwoFactorService $twoFactor, EntityManagerInterface $em): Response
    {
        $this->throttle($request);
        $data = $this->payload($request);
        $challenge = $challenges->findOneByPlainToken((string) ($data['challenge'] ?? ''));
        $code = (string) ($data['code'] ?? '');

        if (null === $challenge || !$challenge->isUsable()) {
            $this->audit->log('auth.2fa', AuditOutcome::FAILURE, context: ['reason' => 'challenge_invalid_or_expired'], actor: $challenge?->getUser());
            throw new UnauthorizedHttpException('Bearer', 'Sessione di accesso scaduta: ripeti il login.');
        }

        $user = $challenge->getUser();
        $method = $twoFactor->verifyCode($user, $code);
        if (null === $method) {
            $challenge->registerFailedAttempt();
            $em->flush();
            $this->audit->log('auth.2fa', AuditOutcome::FAILURE, resourceType: 'user', resourceId: (string) $user->getId(),
                context: ['reason' => 'invalid_code', 'remaining_attempts' => $challenge->getRemainingAttempts()], actor: $user);
            throw new UnauthorizedHttpException('Bearer', $challenge->getRemainingAttempts() > 0
                ? 'Codice non valido.'
                : 'Troppi tentativi errati: ripeti il login.');
        }

        $challenge->consume();
        $em->flush();
        $this->audit->log('auth.2fa', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(),
            context: ['first_factor' => $challenge->getMethod(), 'second_factor' => $method], actor: $user);

        return $this->responder->issueTokens($user);
    }

    private function throttle(Request $request): void
    {
        if (!$this->publicAuthLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : [];
    }
}
