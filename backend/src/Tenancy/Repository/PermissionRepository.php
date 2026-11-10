<?php

namespace App\Tenancy\Repository;

use App\Tenancy\Entity\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Permission>
 */
class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<Permission>
     */
    public function findActiveByCodes(array $codes): array
    {
        if ([] === $codes) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.code IN (:codes)')
            ->andWhere('p.obsolete = false')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, Permission> indicizzati per codice */
    public function findAllIndexedByCode(): array
    {
        $indexed = [];
        foreach ($this->findAll() as $permission) {
            $indexed[$permission->getCode()] = $permission;
        }

        return $indexed;
    }
}
