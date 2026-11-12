<?php

namespace App\Pratiche\Repository;

use App\Pratiche\Entity\Pagamento;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Pagamento>
 */
class PagamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pagamento::class);
    }

    public function findOneInTenant(Tenant $tenant, string $id): ?Pagamento
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]) : null;
    }

    /** @return list<Pagamento> tutti i pagamenti del tenant (la visibilità per categoria la applica il chiamante) */
    public function allInTenant(Tenant $tenant, int $limit = 1000): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('d', 'r', 's')
            ->leftJoin('p.documento', 'd')
            ->leftJoin('p.ricevuta', 'r')
            ->leftJoin('p.scadenzaCollegata', 's')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Pagamento> i pagamenti collegati a un documento (come origine o ricevuta) */
    public function perDocumento(\App\Documenti\Entity\Documento $documento): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.documento = :d OR p.ricevuta = :d')
            ->setParameter('d', $documento->getId(), 'uuid')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
