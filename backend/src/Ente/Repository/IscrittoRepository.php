<?php

namespace App\Ente\Repository;

use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Iscritto>
 */
class IscrittoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Iscritto::class);
    }

    /**
     * Iscritti dell'ente per cognome e nome, facoltativamente di un gruppo e
     * con un testo nel nome ("mario rossi" o "rossi mario").
     *
     * @return list<Iscritto>
     */
    public function cerca(Tenant $ente, ?Gruppo $gruppo, string $testo): array
    {
        $qb = $this->createQueryBuilder('i')->select('DISTINCT i')
            ->leftJoin('i.gruppi', 'g')
            ->where('i.tenant = :t')->setParameter('t', $ente->getId(), 'uuid')
            ->orderBy('i.cognome', 'ASC')->addOrderBy('i.nome', 'ASC');
        if (null !== $gruppo) {
            $qb->andWhere(':gr MEMBER OF i.gruppi')->setParameter('gr', $gruppo->getId(), 'uuid');
        }
        $testo = trim($testo);
        if ('' !== $testo) {
            $qb->andWhere('LOWER(CONCAT(i.nome, \' \', i.cognome)) LIKE :q OR LOWER(CONCAT(i.cognome, \' \', i.nome)) LIKE :q')->setParameter('q', '%'.mb_strtolower($testo).'%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Iscritto> */
    public function delGruppo(Gruppo $gruppo): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere(':g MEMBER OF i.gruppi')->setParameter('g', $gruppo->getId(), 'uuid')
            ->getQuery()->getResult();
    }

    /** @return array<string, int> numero di iscritti per id del gruppo */
    public function conteggiPerGruppo(Tenant $ente): array
    {
        $rows = $this->getEntityManager()->createQuery('SELECT g.id AS id, COUNT(i.id) AS n FROM '.Iscritto::class.' i JOIN i.gruppi g WHERE i.tenant = :t GROUP BY g.id')
            ->setParameter('t', $ente->getId(), 'uuid')
            ->getScalarResult();

        return array_column(array_map(static fn (array $r) => ['id' => (string) $r['id'], 'n' => (int) $r['n']], $rows), 'n', 'id');
    }
}
