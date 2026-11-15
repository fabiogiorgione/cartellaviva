<?php

namespace App\ConnettoreAi\Repository;

use App\ConnettoreAi\Entity\ConnettoreAi;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function perRefreshToken(string $hash): ?ConnettoreAi
    {
        return $this->findOneBy(['refreshTokenHash' => $hash]);
    }

    public function perRefreshPrecedente(string $hash): ?ConnettoreAi
    {
        return $this->findOneBy(['refreshPrecedenteHash' => $hash]);
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
