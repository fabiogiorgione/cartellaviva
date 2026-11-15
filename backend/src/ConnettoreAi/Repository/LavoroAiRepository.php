<?php

namespace App\ConnettoreAi\Repository;

use App\ConnettoreAi\Entity\ConnettoreAi;
use App\ConnettoreAi\Entity\LavoroAi;
use App\Documenti\Entity\Documento;
use App\Pratiche\Entity\Pratica;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<LavoroAi> */
class LavoroAiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LavoroAi::class);
    }

    public function perPraticaDocumento(Pratica $p, Documento $d, string $tipo = LavoroAi::PRATICA_VOCI): ?LavoroAi
    {
        return $this->findOneBy(['pratica' => $p, 'documento' => $d, 'tipo' => $tipo]);
    }

    /**
     * Prende fino a $limite lavori in attesa della famiglia e li assegna al
     * connettore. FOR UPDATE SKIP LOCKED: due richieste insieme non prendono
     * lo stesso lavoro. Va chiamato dentro una transazione.
     *
     * @return list<LavoroAi>
     */
    public function prendi(ConnettoreAi $c, int $limite, \DateTimeImmutable $ora): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM lavoro_ai WHERE tenant_id = :t AND stato = :s ORDER BY creato_il ASC LIMIT '.max(1, min(3, $limite)).' FOR UPDATE SKIP LOCKED',
            ['t' => $c->getTenant()->getId()->toRfc4122(), 's' => LavoroAi::IN_ATTESA],
        );
        $lavori = [];
        foreach ($ids as $id) {
            $l = $this->find(Uuid::fromString((string) $id));
            if (null !== $l) {
                $l->assegna($c, $ora);
                $lavori[] = $l;
            }
        }

        return $lavori;
    }

    public function inAttesa(Tenant $tenant): int
    {
        return (int) $this->count(['tenant' => $tenant, 'stato' => LavoroAi::IN_ATTESA]);
    }

    /** @return list<LavoroAi> assegnati con il lease scaduto */
    public function scaduti(\DateTimeImmutable $ora): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.stato = :s')->setParameter('s', LavoroAi::ASSEGNATO)
            ->andWhere('l.scadeIl < :ora')->setParameter('ora', $ora)
            ->getQuery()->getResult();
    }

    /** @return list<LavoroAi> in attesa (per il ritorno al server) */
    public function tuttiInAttesa(): array
    {
        return $this->findBy(['stato' => LavoroAi::IN_ATTESA], ['creatoIl' => 'ASC'], 2000);
    }

    /** @return list<LavoroAi> del connettore (per la revoca) */
    public function delConnettore(ConnettoreAi $c): array
    {
        return $this->findBy(['connettore' => $c]);
    }
}
