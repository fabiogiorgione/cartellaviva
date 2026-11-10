<?php

namespace App\Account\Repository;

use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Anagrafica>
 */
class AnagraficaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Anagrafica::class);
    }

    /**
     * Unicità tra le anagrafiche dei TITOLARI di un account. Le anagrafiche dei
     * membri della famiglia sono escluse: lo stesso figlio può comparire nei tenant
     * di entrambi i genitori (l'unicità dei membri vale per tenant, vedi
     * MembroFamigliaRepository::codiceFiscaleExistsInTenant).
     */
    public function isCodiceFiscaleUnique(string $codiceFiscale, Uuid $excludeId): bool
    {
        $count = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->innerJoin(User::class, 'u', 'WITH', 'u.anagrafica = a')
            ->andWhere('a.codiceFiscale = :value')
            ->andWhere('a.id != :id')
            ->setParameter('value', strtoupper($codiceFiscale))
            ->setParameter('id', $excludeId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return 0 === $count;
    }

    public function isVatUnique(string $vat, Uuid $excludeId): bool
    {
        return $this->isUnique('vat', $vat, $excludeId);
    }

    private function isUnique(string $field, string $value, Uuid $excludeId): bool
    {
        $count = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere("a.$field = :value")
            ->andWhere('a.id != :id')
            ->setParameter('value', $value)
            ->setParameter('id', $excludeId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return 0 === $count;
    }
}
