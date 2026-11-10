<?php

namespace App\Ente\MessageHandler;

use App\Ente\AutomazioniEnte;
use App\Ente\Message\AutomazioniEntiMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AutomazioniEntiHandler
{
    public function __construct(
        private readonly AutomazioniEnte $automazioni,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{rinnovi: int, ricorrenze: int, fileEliminati: int} */
    public function __invoke(AutomazioniEntiMessage $message): array
    {
        $result = $this->automazioni->esegui();
        $this->logger->info('Automazioni enti: {result}', ['result' => json_encode($result)]);

        return $result;
    }
}
