<?php

namespace App\ConnettoreAi\Repository;

use App\ConnettoreAi\Entity\ConnettoreAi;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ConnettoreAi> */
class ConnettoreAiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnettoreAi::class);
    }

    public function perAccessToken(string $hash): ?ConnettoreAi
    {
        return $this->findOneBy(['accessTokenHash' => $hash]);
    }

    /**
     * Il connettore con quel refresh token, attuale o precedente, con un blocco
     * in scrittura (dentro una transazione): un rinnovo alla volta.
     */
    public function perRefreshBloccato(string $hash): ?ConnettoreAi
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.refreshTokenHash = :h OR c.refreshPrecedenteHash = :h')->setParameter('h', $hash)
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /** @return list<ConnettoreAi> della famiglia, non revocati, dal più recente */
    public function attivi(Tenant $tenant): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :t')->setParameter('t', $tenant->getId(), 'uuid')
            ->andWhere('c.revocatoIl IS NULL')
            ->orderBy('c.approvatoIl', 'DESC')
            ->getQuery()->getResult();
    }

    /** Un connettore online della famiglia (il più recente), o null. */
    public function online(Tenant $tenant, \DateTimeImmutable $ora): ?ConnettoreAi
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :t')->setParameter('t', $tenant->getId(), 'uuid')
            ->andWhere('c.revocatoIl IS NULL')
            ->andWhere('c.ultimoContatto > :da')->setParameter('da', $ora->modify('-'.ConnettoreAi::ONLINE_SECONDI.' seconds'))
            ->orderBy('c.ultimoContatto', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
