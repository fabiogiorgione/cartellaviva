<?php

namespace App\Shared\State;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Elenchi paginati: il provider mette il totale tra gli attributi della
 * richiesta, qui diventa l'header X-Total-Count (esposto via CORS). Così il
 * corpo resta un semplice array e i client che non paginano non cambiano.
 */
#[AsEventListener]
final class TotaleRisultatiListener
{
    public const ATTRIBUTO = '_x_total_count';

    public function __invoke(ResponseEvent $event): void
    {
        $totale = $event->getRequest()->attributes->get(self::ATTRIBUTO);
        if (\is_int($totale)) {
            $event->getResponse()->headers->set('X-Total-Count', (string) $totale);
        }
    }
}
