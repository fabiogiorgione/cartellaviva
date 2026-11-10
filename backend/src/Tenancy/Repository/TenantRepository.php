<?php

namespace App\Tenancy\Repository;

use App\Account\Entity\User;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function findByPublicId(string $id): ?Tenant
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * Tenant su cui l'utente ha una Membership attiva.
     *
     * @return list<Tenant>
     */
    public function findForMember(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('App\Tenancy\Entity\Membership', 'm', 'WITH', 'm.tenant = t AND m.user = :user AND m.status = :active')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
