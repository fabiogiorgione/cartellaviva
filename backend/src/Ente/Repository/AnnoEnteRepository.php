<?php

namespace App\Ente\Repository;

use App\Ente\Entity\AnnoEnte;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnnoEnte>
 */
class AnnoEnteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnnoEnte::class);
    }

    /**
     * L'anno in corso: il più recente non archiviato. A parità di data di
     * creazione (al secondo) decide l'id, UUID v7 crescente.
     */
    public function corrente(Tenant $ente): ?AnnoEnte
    {
        return $this->findOneBy(['tenant' => $ente, 'archiviato' => false], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
