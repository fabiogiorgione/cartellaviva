<?php

namespace App\Pratiche\Repository;

use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaVoce;
use App\Pratiche\Enum\PraticaStato;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Pratica> */
class PraticaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pratica::class);
    }

    public function findOneInTenant(Tenant $tenant, string $id): ?Pratica
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]) : null;
    }

    /** @return list<Pratica> le più recenti prima */
    public function allInTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['anno' => 'DESC', 'createdAt' => 'DESC'], 200);
    }

    /** @return list<Pratica> pratiche in corso del tenant (per analizzare i documenti nuovi) */
    public function inCorso(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant, 'stato' => PraticaStato::IN_CORSO]);
    }

    /** Pratica dello stesso modello dell'anno prima (per "c'era l'anno scorso"). */
    public function annoPrima(Pratica $pratica): ?Pratica
    {
        return $this->findOneBy(['tenant' => $pratica->getTenant(), 'modello' => $pratica->getModello(), 'anno' => $pratica->getAnno() - 1], ['createdAt' => 'DESC']);
    }

    /** @return list<PraticaVoce> */
    public function voci(Pratica $pratica): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('v', 'd', 'p', 'c')
            ->from(PraticaVoce::class, 'v')
            ->leftJoin('v.documento', 'd')
            ->leftJoin('d.categoria', 'c')
            ->leftJoin('v.persona', 'p')
            ->andWhere('v.pratica = :p')
            ->setParameter('p', $pratica->getId(), 'uuid')
            ->orderBy('v.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> id dei documenti già analizzati per la pratica */
    public function documentiAnalizzati(Pratica $pratica): array
    {
        return array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT documento_id FROM pratica_analisi WHERE pratica_id = :p',
            ['p' => $pratica->getId()->toRfc4122()],
        ));
    }
}
