<?php

namespace App\Tenancy\Repository;

use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MembroFamiglia>
 */
class MembroFamigliaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembroFamiglia::class);
    }

    /** @return list<MembroFamiglia> */
    public function findForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('a')
            ->innerJoin('m.anagrafica', 'a')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->orderBy('a.nome', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneInTenant(Tenant $tenant, string $id): ?MembroFamiglia
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]) : null;
    }

    /**
     * Membri indicati per id: tutti devono appartenere al tenant, altrimenti null.
     *
     * @param list<string> $ids
     *
     * @return list<MembroFamiglia>|null
     */
    public function findAllInTenant(Tenant $tenant, array $ids): ?array
    {
        $ids = array_values(array_unique($ids));
        if ([] === $ids) {
            return [];
        }
        foreach ($ids as $id) {
            if (!Uuid::isValid($id)) {
                return null;
            }
        }
        $found = $this->createQueryBuilder('m')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setParameter('ids', array_map(static fn (string $id) => Uuid::fromString($id)->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        return \count($found) === \count($ids) ? $found : null;
    }

    /** Codice fiscale già usato da un altro membro dello stesso tenant. */
    public function codiceFiscaleExistsInTenant(Tenant $tenant, string $codiceFiscale, ?Uuid $excludeMembroId): bool
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.anagrafica', 'a')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('a.codiceFiscale = :cf')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setParameter('cf', strtoupper($codiceFiscale));
        if (null !== $excludeMembroId) {
            $qb->andWhere('m.id != :exclude')->setParameter('exclude', $excludeMembroId, 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
