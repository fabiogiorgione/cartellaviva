<?php

namespace App\ConnettoreAi\Security;

use App\ConnettoreAi\ConnettoriAi;
use App\ConnettoreAi\Repository\ConnettoreAiRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Token di accesso dei connettori (firewall "ai_connector"). Rifiuta i token
 * scaduti e i connettori revocati, e revoca il connettore se chi l'ha
 * approvato non ha più il permesso connettore_ai:manage nella famiglia.
 */
final class TokenConnettoreHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ConnettoreAiRepository $connettori,
        private readonly ConnettoriAi $servizio,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $c = $this->connettori->perAccessToken(ConnettoriAi::hash($accessToken));
        $ora = new \DateTimeImmutable();
        if (null === $c || !$c->accessoValido($ora)) {
            throw new BadCredentialsException('Token del connettore non valido o scaduto.');
        }
        if (!$this->servizio->approvazioneValida($c)) {
            $this->servizio->revoca($c, 'permesso_perso');
            throw new BadCredentialsException('Connettore scollegato.');
        }
        $this->servizio->contatto($c);

        return new UserBadge('connettore:'.$c->getId()->toRfc4122(), static fn () => new ConnettoreUser($c));
    }
}
