<?php

namespace App\Import\Repository;

use App\Import\Entity\ImportSource;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ImportSource>
 */
class ImportSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportSource::class);
    }

    public function findOneInTenant(Tenant $tenant, string $id): ?ImportSource
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]) : null;
    }

    /** @return list<ImportSource> */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['createdAt' => 'ASC']);
    }

    public function findByOAuthStateHash(string $hash): ?ImportSource
    {
        return $this->findOneBy(['oauthStateHash' => $hash]);
    }

    /** @return iterable<ImportSource> */
    public function iterateWithTokensNotAtVersion(int $version): iterable
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.encryptedDek IS NOT NULL')
            ->andWhere('s.keyVersion != :v')
            ->setParameter('v', $version)
            ->getQuery()
            ->toIterable();
    }
}
