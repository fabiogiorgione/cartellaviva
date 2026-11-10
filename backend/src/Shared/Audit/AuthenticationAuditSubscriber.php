<?php

namespace App\Shared\Audit;

use App\Account\Entity\User;
use App\Shared\Enum\AuditOutcome;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Audit di login (riusciti e falliti), refresh del token e logout.
 * Le richieste autenticate col solo JWT non vengono registrate qui: sono una
 * per chiamata API e verrebbero tracciate le decisioni del voter.
 */
final class AuthenticationAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => ['onLogout', -10],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $action = match ($event->getRequest()->attributes->get('_route')) {
            'api_login' => 'auth.login',
            'api_token_refresh' => 'auth.token_refresh',
            default => null,
        };
        if (null === $action) {
            return;
        }

        $user = $event->getUser();
        if ($user instanceof User) {
            // Con la 2FA attiva questo è solo il primo fattore: vedi auth.2fa_challenge / auth.2fa.
            $context = 'auth.login' === $action ? ['method' => 'password', 'second_factor' => $user->isTwoFactorEnabled() ? 'required' : 'none'] : [];
            $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: $context, actor: $user);
        }
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!\in_array($route, ['api_login', 'api_token_refresh'], true)) {
            return;
        }

        $email = null;
        if ('api_login' === $route) {
            $payload = json_decode($request->getContent(), true);
            $email = \is_array($payload) && \is_string($payload['email'] ?? null) ? mb_substr(User::normalizeEmail($payload['email']), 0, 180) : null;
        }

        $this->audit->log(
            'api_login' === $route ? 'auth.login' : 'auth.token_refresh',
            AuditOutcome::FAILURE,
            context: [
                'reason' => $event->getException()->getMessageKey(),
                'throttled' => $event->getException() instanceof TooManyLoginAttemptsAuthenticationException,
            ],
            actorEmail: $email,
        );
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        $this->audit->log('auth.logout', AuditOutcome::SUCCESS, actor: $user instanceof User ? $user : null);

        // API stateless: al posto del redirect di default rispondiamo 204.
        $response = $event->getResponse();
        if (null === $response || $response->isRedirection()) {
            $event->setResponse(new JsonResponse(null, Response::HTTP_NO_CONTENT));
        }
    }
}
