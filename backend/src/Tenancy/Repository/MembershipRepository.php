<?php

namespace App\Tenancy\Repository;

use App\Account\Entity\User;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Membership>
 */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /**
     * Membership attiva con ruolo, permessi diretti e permessi dei gruppi
     * caricati in un'unica query (serve al voter a ogni richiesta).
     */
    public function findActiveWithPermissions(User $user, Tenant $tenant): ?Membership
    {
        return $this->createQueryBuilder('m')
            ->addSelect('r', 'p', 'g', 'gp')
            ->innerJoin('m.role', 'r')
            ->leftJoin('r.permissions', 'p')
            ->leftJoin('r.permissionGroups', 'g')
            ->leftJoin('g.permissions', 'gp')
            ->innerJoin('m.user', 'u')
            ->andWhere('m.user = :user')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.status = :active')
            ->andWhere('u.active = true')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Membership> */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('t', 'r')
            ->innerJoin('m.tenant', 't')
            ->innerJoin('m.role', 'r')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :active')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Membership> */
    public function findForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u', 'r')
            ->innerJoin('m.user', 'u')
            ->innerJoin('m.role', 'r')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneInTenant(Tenant $tenant, string $id): ?Membership
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]);
    }

    public function findOneByUserAndTenant(User $user, Tenant $tenant): ?Membership
    {
        return $this->findOneBy(['user' => $user, 'tenant' => $tenant]);
    }

    /** Owner attivi del tenant, con lock pessimistico per evitare race sulla regola dell'ultimo owner. */
    /** Utenti con ruolo proprietario attivo nel tenant, dal più anziano. @return list<User> */
    public function findActiveOwnerUsers(Tenant $tenant): array
    {
        /** @var list<Membership> $rows */
        $rows = $this->createQueryBuilder('m')
            ->addSelect('u')
            ->innerJoin('m.role', 'r')
            ->innerJoin('m.user', 'u')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.status = :active')
            ->andWhere('u.active = true')
            ->andWhere('r.tenant IS NULL')
            ->andWhere('r.slug = :owner')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->setParameter('owner', Role::OWNER)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (Membership $m) => $m->getUser(), $rows);
    }

    public function countActiveOwnersForUpdate(Tenant $tenant): int
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->innerJoin('m.role', 'r')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.status = :active')
            ->andWhere('r.tenant IS NULL')
            ->andWhere('r.slug = :owner')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setParameter('active', MembershipStatus::ACTIVE)
            ->setParameter('owner', Role::OWNER)
            ->getQuery()
            ->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)
            ->getArrayResult();

        return \count($rows);
    }

    public function countUsingRole(Role $role): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.role = :role')
            ->setParameter('role', $role->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
