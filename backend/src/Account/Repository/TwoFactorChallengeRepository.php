<?php

namespace App\Account\Repository;

use App\Account\Entity\TwoFactorChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TwoFactorChallenge>
 */
class TwoFactorChallengeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TwoFactorChallenge::class);
    }

    public function findOneByPlainToken(string $plainToken): ?TwoFactorChallenge
    {
        return '' === $plainToken ? null : $this->findOneBy(['tokenHash' => TwoFactorChallenge::hash($plainToken)]);
    }

    /** Pulizia delle challenge scadute (da schedulare, es. cron giornaliero). */
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable('-1 day'))
            ->getQuery()
            ->execute();
    }
}
