<?php

namespace App\Shared\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Carica un'entità dall'id ricevuto (URL o corpo della richiesta): un id
 * malformato vale come "non trovato", mai come errore 500.
 */
final class PerId
{
    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T|null
     */
    public static function trova(EntityManagerInterface $em, string $classe, mixed $id): ?object
    {
        return \is_string($id) && Uuid::isValid($id) ? $em->find($classe, Uuid::fromString($id)) : null;
    }
}
