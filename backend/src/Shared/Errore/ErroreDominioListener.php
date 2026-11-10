<?php

namespace App\Shared\Errore;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prima dei gestori di Symfony e API Platform: un errore di dominio diventa
 * l'eccezione HTTP equivalente, quindi la risposta (stato, messaggio, formato)
 * è la stessa di quando i servizi lanciavano direttamente eccezioni HTTP.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
final class ErroreDominioListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        if (!$e instanceof ErroreDominio) {
            return;
        }
        $event->setThrowable(match (true) {
            $e instanceof DatiNonValidi => new UnprocessableEntityHttpException($e->getMessage(), $e),
            $e instanceof Conflitto => new ConflictHttpException($e->getMessage(), $e),
            $e instanceof NonTrovato => new NotFoundHttpException($e->getMessage(), $e),
            $e instanceof NonConsentito => new AccessDeniedHttpException($e->getMessage(), $e),
            $e instanceof NonPiuValido => new GoneHttpException($e->getMessage(), $e),
            $e instanceof TroppiTentativi => new TooManyRequestsHttpException(null, $e->getMessage(), $e),
            default => throw new \LogicException('Errore di dominio senza traduzione HTTP: '.$e::class),
        });
    }
}
