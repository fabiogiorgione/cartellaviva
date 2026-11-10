<?php

namespace App\Tenancy\Repository;

use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function findSystemRole(string $slug): ?Role
    {
        return $this->findOneBy(['slug' => $slug, 'tenant' => null]);
    }

    public function getSystemRole(string $slug): Role
    {
        return $this->findSystemRole($slug)
            ?? throw new \LogicException(sprintf('Ruolo di sistema "%s" assente: eseguire app:roles:sync.', $slug));
    }

    /**
     * Ruoli utilizzabili nel tenant: quelli di sistema applicabili al suo tipo
     * più quelli personalizzati del tenant.
     *
     * @return list<Role>
     */
    public function findAssignableIn(Tenant $tenant): array
    {
        // I ruoli personalizzati valgono solo in modalità avanzata.
        $qb = $this->createQueryBuilder('r')
            ->addSelect('p', 'g', 'gp')
            ->leftJoin('r.permissions', 'p')
            ->leftJoin('r.permissionGroups', 'g')
            ->leftJoin('g.permissions', 'gp')
            ->andWhere($tenant->isModalitaAvanzata()
                ? '(r.tenant IS NULL AND (r.tenantType IS NULL OR r.tenantType = :type)) OR r.tenant = :tenant'
                : 'r.tenant IS NULL AND (r.tenantType IS NULL OR r.tenantType = :type)')
            ->setParameter('type', $tenant->getType())
            ->orderBy('r.name', 'ASC');
        if ($tenant->isModalitaAvanzata()) {
            $qb->setParameter('tenant', $tenant->getId(), 'uuid');
        }
        /** @var list<Role> $roles */
        $roles = array_values(array_filter($qb->getQuery()->getResult(), static fn (Role $r) => $r->isAssignableIn($tenant)));

        // Prima i ruoli di sistema, nell'ordine canonico, poi quelli personalizzati.
        $order = [Role::OWNER => 0, 'segreteria' => 1, Role::STAFF => 2, Role::MEMBER => 3, Role::VIEWER => 4];
        usort($roles, static fn (Role $a, Role $b) => [(int) !$a->isSystem(), $order[$a->getSlug()] ?? 99, $a->getName()]
            <=> [(int) !$b->isSystem(), $order[$b->getSlug()] ?? 99, $b->getName()]);

        return $roles;
    }

    public function findAssignableById(Tenant $tenant, string $id): ?Role
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $role = $this->find(Uuid::fromString($id));

        return $role?->isAssignableIn($tenant) ? $role : null;
    }
}
