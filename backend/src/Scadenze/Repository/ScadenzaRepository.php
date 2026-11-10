<?php

namespace App\Scadenze\Repository;

use App\Documenti\Entity\Documento;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Enum\ScadenzaStato;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Scadenza>
 */
class ScadenzaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scadenza::class);
    }

    public function findOneInTenant(Tenant $tenant, string $id): ?Scadenza
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]) : null;
    }

    /**
     * @param array{da?: ?\DateTimeImmutable, a?: ?\DateTimeImmutable, stato?: ?ScadenzaStato, documentoId?: ?string, membroId?: ?string} $filters
     *
     * @return list<Scadenza>
     */
    public function search(Tenant $tenant, array $filters, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('d', 'm', 'c')
            ->leftJoin('s.documento', 'd')
            ->leftJoin('s.membro', 'm')
            ->leftJoin('s.categoria', 'c')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->orderBy('s.data', 'ASC')
            ->addOrderBy('s.createdAt', 'ASC')
            ->setMaxResults($limit);
        if (!empty($filters['da'])) {
            $qb->andWhere('s.data >= :da')->setParameter('da', $filters['da'], 'date_immutable');
        }
        if (!empty($filters['a'])) {
            $qb->andWhere('s.data <= :a')->setParameter('a', $filters['a'], 'date_immutable');
        }
        if (!empty($filters['stato'])) {
            $qb->andWhere('s.stato = :stato')->setParameter('stato', $filters['stato']);
        }
        if (!empty($filters['documentoId']) && Uuid::isValid($filters['documentoId'])) {
            $qb->andWhere('s.documento = :doc')->setParameter('doc', Uuid::fromString($filters['documentoId']), 'uuid');
        }
        if (!empty($filters['membroId']) && Uuid::isValid($filters['membroId'])) {
            $qb->andWhere('s.membro = :membro')->setParameter('membro', Uuid::fromString($filters['membroId']), 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /** Scadenze aperte il cui promemoria va inviato entro oggi e non è ancora partito. @return list<Scadenza> */
    public function findPromemoriaDovuti(\DateTimeImmutable $oggi): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('t', 'o')
            ->innerJoin('s.tenant', 't')
            ->innerJoin('s.owner', 'o')
            ->andWhere('s.stato = :aperta')
            ->andWhere('s.promemoriaIl <= :oggi')
            ->andWhere('s.promemoriaInviatoIl IS NULL')
            ->andWhere('s.data >= :oggi')
            ->setParameter('aperta', ScadenzaStato::APERTA)
            ->setParameter('oggi', $oggi, 'date_immutable')
            ->getQuery()
            ->getResult();
    }

    /** Prima di eliminare un documento: le scadenze collegate lo ricordano come "eliminato". */
    public function markDocumentoRimosso(Documento $documento): int
    {
        return (int) $this->createQueryBuilder('s')
            ->update()
            ->set('s.documentoRimosso', 'true')
            ->where('s.documento = :doc')
            ->setParameter('doc', $documento->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }
}
