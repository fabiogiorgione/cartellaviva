<?php

namespace App;

use App\Ente\Message\AutomazioniEntiMessage;
use App\Portale\Message\RiepilogoIdeeMessage;
use App\Scadenze\Message\PromemoriaScadenzeMessage;
use App\Shared\Message\PuliziaPeriodicaMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Attività pianificate, eseguite dal servizio "worker" (trasporto scheduler_default).
 * Ogni attività è un messaggio con il suo handler.
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // un'esecuzione saltata (worker fermo) viene recuperata
            ->processOnlyLastMissedRun(true) // ma una sola volta, non per ogni ora persa
            // add(), non with(): with() crea una nuova Schedule e perde le impostazioni sopra.
            ->add(
                RecurringMessage::every('1 hour', new PuliziaPeriodicaMessage()),
                // Promemoria delle scadenze: ogni mattina alle 7 (ora italiana).
                RecurringMessage::every('1 day', new PromemoriaScadenzeMessage(), new \DateTimeImmutable('07:00', new \DateTimeZone('Europe/Rome'))),
                // Enti: rinnovi alla scadenza, richieste ricorrenti, conservazione (prima dei promemoria).
                RecurringMessage::every('1 day', new AutomazioniEntiMessage(), new \DateTimeImmutable('06:00', new \DateTimeZone('Europe/Rome'))),
                // Portale delle idee: riepilogo delle nuove idee al superadmin, alle 8.
                RecurringMessage::every('1 day', new RiepilogoIdeeMessage(), new \DateTimeImmutable('08:00', new \DateTimeZone('Europe/Rome'))),
            );
    }
}
