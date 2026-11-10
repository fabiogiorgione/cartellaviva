<?php

namespace App\Shared\Repository;

use App\Shared\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** @return list<AuditLog> */
    /**
     * @param bool        $soloAttivita senza i controlli tecnici dei permessi e gli elenchi (registro attività dell'ente)
     * @param string|null $attore       parte dell'email di chi ha agito
     */
    public function findLatest(?Uuid $tenantId, int $limit, ?string $beforeId = null, ?string $outcome = null, ?string $actionPrefix = null, bool $soloAttivita = false, ?string $attore = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC')
            ->setMaxResults($limit);
        if ($soloAttivita) {
            $qb->andWhere("a.action NOT IN ('permission.check', 'documento.list', 'auth.login', 'auth.2fa', 'auth.2fa_challenge')");
        }
        if (null !== $attore && '' !== trim($attore)) {
            $qb->andWhere('LOWER(a.actorEmail) LIKE :attore')->setParameter('attore', '%'.addcslashes(mb_strtolower(trim($attore)), '%_').'%');
        }
        if (null !== $outcome && '' !== $outcome) {
            $qb->andWhere('a.outcome = :outcome')->setParameter('outcome', $outcome);
        }
        if (null !== $actionPrefix && '' !== $actionPrefix) {
            $qb->andWhere('a.action LIKE :action')->setParameter('action', addcslashes($actionPrefix, '%_').'%');
        }

        if (null !== $tenantId) {
            $qb->andWhere('a.tenantId = :tenant')->setParameter('tenant', $tenantId, 'uuid');
        }
        if (null !== $beforeId && ctype_digit($beforeId)) {
            $qb->andWhere('a.id < :before')->setParameter('before', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }
}
