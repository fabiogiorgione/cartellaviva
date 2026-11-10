<?php

namespace App\Shared\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Per i job che lavorano un'entità alla volta: l'errore di una non ferma le
 * altre. Un errore del database chiude l'EntityManager: si riapre, e le
 * entità successive si ricaricano nel nuovo prima di lavorarle.
 */
final class ElaborazioneIsolata
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @template T of object
     *
     * @param iterable<T>       $entita
     * @param callable(T): void $lavoro
     *
     * @return int quante riuscite
     */
    public function perOgni(iterable $entita, callable $lavoro, string $cosa): int
    {
        $riuscite = 0;
        foreach ($entita as $e) {
            $classe = $this->em->getClassMetadata($e::class)->getName();
            $id = $this->em->getClassMetadata($classe)->getIdentifierValues($e);
            try {
                if (!$this->em->contains($e)) {
                    $e = $this->em->find($classe, $id);
                    if (null === $e) {
                        continue; // eliminata nel frattempo
                    }
                }
                $lavoro($e);
                ++$riuscite;
            } catch (\Throwable $errore) {
                $this->logger->error('{cosa}: elaborazione non riuscita per {id}', ['cosa' => $cosa, 'id' => json_encode(array_map('strval', $id)), 'exception' => $errore]);
                if (!$this->em->isOpen()) {
                    $this->doctrine->resetManager();
                }
            }
        }

        return $riuscite;
    }
}
