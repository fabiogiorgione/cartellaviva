<?php

namespace App\Documenti\Repository;

use App\Documenti\Entity\Categoria;
use App\Documenti\Entity\Documento;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Categoria>
 */
class CategoriaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Categoria::class);
    }

    /** Categorie di sistema + personalizzate del tenant. @return list<Categoria> */
    public function findUsableIn(Tenant $tenant): array
    {
        /** @var list<Categoria> $list */
        $list = $this->createQueryBuilder('c')
            ->andWhere('c.tenant IS NULL OR c.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->orderBy('c.nome', 'ASC')
            ->getQuery()
            ->getResult();
        usort($list, static fn (Categoria $a, Categoria $b) => [(int) !$a->isSystem(), $a->getNome()] <=> [(int) !$b->isSystem(), $b->getNome()]);

        return $list;
    }

    public function findUsableById(Tenant $tenant, string $id): ?Categoria
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $categoria = $this->find(Uuid::fromString($id));

        return $categoria?->isUsableIn($tenant) ? $categoria : null;
    }

    /**
     * Categorie usabili nel tenant con questo nome, senza distinguere maiuscole e
     * minuscole. Può restituirne più di una (una di sistema e una del tenant).
     *
     * @return list<Categoria>
     */
    public function findUsableByNome(Tenant $tenant, string $nome): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant IS NULL OR c.tenant = :tenant')
            ->andWhere('LOWER(c.nome) = :nome')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setParameter('nome', mb_strtolower(trim($nome)))
            ->getQuery()
            ->getResult();
    }

    public function findSystemBySlug(string $slug): ?Categoria
    {
        return $this->findOneBy(['slug' => $slug, 'tenant' => null]);
    }

    /** @return array<string, int> numero di documenti del tenant per id di categoria */
    public function contaDocumentiPerCategoria(Tenant $tenant): array
    {
        $righe = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(d.categoria) AS categoria', 'COUNT(d.id) AS n')
            ->from(Documento::class, 'd')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->groupBy('d.categoria')
            ->getQuery()
            ->getScalarResult();

        $conteggi = [];
        foreach ($righe as $r) {
            $conteggi[(string) $r['categoria']] = (int) $r['n'];
        }

        return $conteggi;
    }

    public function countDocumenti(Categoria $categoria): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Documento::class, 'd')
            ->andWhere('d.categoria = :categoria')
            ->setParameter('categoria', $categoria->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
