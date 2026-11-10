<?php

namespace App\Account\Repository;

use App\Account\Entity\Geo\GeoCountry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GeoCountry>
 */
class GeoCountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeoCountry::class);
    }

    /** @return list<GeoCountry> */
    public function search(?string $query, int $limit = 300): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.nameIt', 'ASC')->setMaxResults($limit);
        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('LOWER(c.nameIt) LIKE :q')->setParameter('q', mb_strtolower(trim($query)).'%');
        }

        return $qb->getQuery()->getResult();
    }
}
