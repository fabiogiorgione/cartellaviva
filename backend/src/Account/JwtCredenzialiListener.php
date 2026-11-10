<?php

namespace App\Account;

use App\Account\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Dopo un cambio di password o email i JWT già emessi non valgono più, anche
 * se non sono scaduti (durano 15 minuti): così "chiudi le sessioni" chiude
 * davvero anche quelle con un access token ancora in corso.
 */
#[AsEventListener(event: Events::JWT_DECODED)]
final class JwtCredenzialiListener
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function __invoke(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $email = $payload['email'] ?? null;
        $iat = $payload['iat'] ?? null;
        if (!\is_string($email) || !is_numeric($iat)) {
            return;
        }
        $cambiate = $this->users->findOneByEmail($email)?->getCredenzialiCambiateIl();
        if (null !== $cambiate && (int) $iat < $cambiate->getTimestamp()) {
            $event->markAsInvalid();
        }
    }
}
