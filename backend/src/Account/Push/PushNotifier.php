<?php

namespace App\Account\Push;

use App\Account\Entity\DispositivoPush;
use App\Account\Entity\User;
use App\Account\Message\InviaPushMessage;
use App\Shared\Errore\DatiNonValidi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Notifiche push: si affiancano alle email, con lo STESSO livello di
 * riservatezza (come l'oggetto dell'email: mai titoli di documenti o
 * richieste). L'invio avviene nel worker; senza configurazione FCM o senza
 * telefoni registrati non succede nulla.
 */
final class PushNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly PushGatewayInterface $gateway,
    ) {
    }

    /** @param string $route pagina dell'app da aprire toccando la notifica (es. "/richieste") */
    public function notifica(User $user, string $titolo, string $testo, string $route): void
    {
        if (!$this->gateway->isEnabled() || !$user->isActive()) {
            return;
        }
        if (null === $this->em->getRepository(DispositivoPush::class)->findOneBy(['user' => $user])) {
            return;
        }
        $this->bus->dispatch(new InviaPushMessage((string) $user->getId(), $titolo, $testo, $route));
    }

    public function registra(User $user, string $token, string $piattaforma): void
    {
        $token = trim($token);
        if ('' === $token || \strlen($token) > 512 || !\in_array($piattaforma, DispositivoPush::PIATTAFORME, true)) {
            throw new DatiNonValidi('Dispositivo non valido.');
        }
        $d = $this->em->getRepository(DispositivoPush::class)->findOneBy(['token' => $token]);
        if ($d instanceof DispositivoPush) {
            // Stesso telefono, altro account: le notifiche seguono chi è entrato.
            $d->assegnaA($user, $piattaforma);
        } else {
            $this->em->persist(new DispositivoPush($user, $token, $piattaforma));
        }
        $this->em->flush();
    }

    public function rimuovi(User $user, string $token): void
    {
        $d = $this->em->getRepository(DispositivoPush::class)->findOneBy(['token' => trim($token), 'user' => $user]);
        if ($d instanceof DispositivoPush) {
            $this->em->remove($d);
            $this->em->flush();
        }
    }
}
