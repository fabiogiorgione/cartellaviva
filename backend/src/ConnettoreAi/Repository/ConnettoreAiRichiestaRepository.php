<?php

namespace App\ConnettoreAi\Repository;

use App\ConnettoreAi\Entity\ConnettoreAiRichiesta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ConnettoreAiRichiesta> */
class ConnettoreAiRichiestaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnettoreAiRichiesta::class);
    }

    public function perDeviceCode(string $hash): ?ConnettoreAiRichiesta
    {
        return $this->findOneBy(['deviceCodeHash' => $hash]);
    }

    /** La richiesta viva (in attesa, non scaduta) con quel codice. */
    public function inAttesa(string $userCode, \DateTimeImmutable $ora): ?ConnettoreAiRichiesta
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.userCode = :c')->setParameter('c', $userCode)
            ->andWhere('r.stato = :s')->setParameter('s', ConnettoreAiRichiesta::IN_ATTESA)
            ->andWhere('r.scadeIl > :ora')->setParameter('ora', $ora)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function codiceInUso(string $userCode, \DateTimeImmutable $ora): bool
    {
        return null !== $this->inAttesa($userCode, $ora);
    }

    /** Le richieste scadute da più di un giorno non servono più. */
    public function pulisci(\DateTimeImmutable $ora): int
    {
        return $this->createQueryBuilder('r')->delete()
            ->andWhere('r.scadeIl < :prima')->setParameter('prima', $ora->modify('-1 day'))
            ->getQuery()->execute();
    }
}
