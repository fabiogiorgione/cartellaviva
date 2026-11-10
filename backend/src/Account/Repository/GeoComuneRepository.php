<?php

namespace App\Account\Repository;

use App\Account\Entity\Geo\GeoComune;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GeoComune>
 */
class GeoComuneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeoComune::class);
    }

    /**
     * Ricerca per autocompletamento. I comuni soppressi servono per i luoghi di
     * nascita (chi è nato in un comune poi fuso deve poterlo indicare).
     *
     * @return list<GeoComune>
     */
    public function search(string $query, bool $includeDiscontinued, int $limit = 30): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('LOWER(c.nameIt) LIKE :q')
            ->setParameter('q', mb_strtolower(trim($query)).'%')
            ->orderBy('c.nameIt', 'ASC')
            ->addOrderBy('c.endDate', 'DESC')
            ->setMaxResults($limit);
        if (!$includeDiscontinued) {
            $qb->andWhere('c.status = :active')->setParameter('active', GeoComune::STATUS_ACTIVE);
        }

        return $qb->getQuery()->getResult();
    }
}
