<?php

namespace App\Tenancy\Repository;

use App\Tenancy\Entity\PermissionGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PermissionGroup>
 */
class PermissionGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionGroup::class);
    }

    public function findSystemGroup(string $slug): ?PermissionGroup
    {
        return $this->findOneBy(['slug' => $slug, 'tenant' => null]);
    }
}
