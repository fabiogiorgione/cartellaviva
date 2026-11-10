<?php

namespace App\Tenancy\Repository;

use App\Tenancy\Entity\Invitation;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findOneByPlainToken(string $plainToken): ?Invitation
    {
        if ('' === $plainToken) {
            return null;
        }

        return $this->findOneBy(['tokenHash' => Invitation::hashToken($plainToken)]);
    }

    /** @return list<Invitation> */
    public function findForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('r')
            ->innerJoin('i.role', 'r')
            ->andWhere('i.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneInTenant(Tenant $tenant, string $id): ?Invitation
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]);
    }

    /** @return list<Invitation> */
    public function findPendingFor(Tenant $tenant, string $email): array
    {
        return array_values(array_filter(
            $this->findBy(['tenant' => $tenant, 'email' => $email]),
            static fn (Invitation $i) => $i->isPending(),
        ));
    }
}
