<?php

namespace App\Import\MessageHandler;

use App\Import\Entity\ImportSource;
use App\Import\ImportRunner;
use App\Import\Message\ImportJobMessage;
use App\Shared\Doctrine\PerId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Worker dell'importazione (servizio "worker" del docker-compose). La logica è in ImportRunner. */
#[AsMessageHandler]
final class ImportJobHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportJobMessage $message): void
    {
        $source = PerId::trova($this->em, ImportSource::class, $message->importSourceId);
        if (null === $source) {
            $this->logger->warning('Import: sorgente {id} non trovata (cancellata?)', ['id' => $message->importSourceId]);

            return;
        }
        $this->runner->run($source);
    }
}
