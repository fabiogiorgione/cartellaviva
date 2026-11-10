<?php

namespace App\Ente\Repository;

use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\Enum\DestinatarioStato;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RichiestaDestinatario>
 */
class RichiestaDestinatarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RichiestaDestinatario::class);
    }

    /**
     * Documenti consegnati (richieste completate) con una validità che scade
     * entro $limite, dal più vicino.
     *
     * @return list<RichiestaDestinatario>
     */
    public function inScadenza(Tenant $ente, \DateTimeImmutable $limite): array
    {
        return $this->getEntityManager()->createQuery('SELECT d, r FROM '.RichiestaDestinatario::class.' d JOIN d.richiesta r WHERE r.tenant = :t AND d.stato = :c AND d.validoFino IS NOT NULL AND d.validoFino <= :limite ORDER BY d.validoFino ASC')
            ->setParameter('t', $ente->getId(), 'uuid')
            ->setParameter('c', DestinatarioStato::COMPLETATA)
            ->setParameter('limite', $limite, 'date_immutable')
            ->getResult();
    }
}
