<?php

namespace App\Account\MessageHandler;

use App\Account\Entity\DispositivoPush;
use App\Account\Entity\User;
use App\Account\Message\InviaPushMessage;
use App\Account\Push\PushGatewayInterface;
use App\Shared\Doctrine\PerId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Invia la push a ogni telefono dell'utente. I token che FCM dichiara non
 * validi vengono eliminati; un errore temporaneo fa ritentare il messaggio.
 */
#[AsMessageHandler]
final class InviaPushHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushGatewayInterface $gateway,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{inviate: int, rimossi: int} */
    public function __invoke(InviaPushMessage $message): array
    {
        $user = PerId::trova($this->em, User::class, $message->userId);
        if (!$user instanceof User || !$user->isActive() || !$this->gateway->isEnabled()) {
            return ['inviate' => 0, 'rimossi' => 0];
        }
        $inviate = 0;
        $rimossi = 0;
        foreach ($this->em->getRepository(DispositivoPush::class)->findBy(['user' => $user]) as $d) {
            if (PushGatewayInterface::TOKEN_NON_VALIDO === $this->gateway->invia($d->getToken(), $message->titolo, $message->testo, ['route' => $message->route])) {
                $this->em->remove($d);
                ++$rimossi;
            } else {
                ++$inviate;
            }
        }
        $this->em->flush();
        $this->logger->info('Push: {inviate} inviate, {rimossi} token rimossi', ['inviate' => $inviate, 'rimossi' => $rimossi]);

        return ['inviate' => $inviate, 'rimossi' => $rimossi];
    }
}
