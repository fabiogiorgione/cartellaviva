<?php

namespace App\Documenti\Repository;

use App\Documenti\Entity\Documento;
use App\Documenti\Indice\IndiceDocumenti;
use App\Documenti\UploadPolicy;
use App\Import\Entity\ImportSource;
use App\Tenancy\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Documento>
 *
 * Ogni query richiede il tenant: non esiste un metodo che restituisca
 * documenti di più tenant.
 */
class DocumentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly IndiceDocumenti $indice)
    {
        parent::__construct($registry, Documento::class);
    }

    public function findOneInTenant(Tenant $tenant, string $id): ?Documento
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'tenant' => $tenant]) : null;
    }

    /**
     * ordina: creato (predefinito) | titolo | data (del documento, o di caricamento se manca); verso: asc | desc (predefinito).
     *
     * ids: solo questi documenti (ricerca per significato: l'ordine lo decide il chiamante).
     *
     * anno, emittente (chiave), tag: anche IndiceDocumenti::NESSUNO per chi non li ha. tipo: UploadPolicy::tipo().
     *
     * @param array{categoriaId?: ?string, membroId?: ?string, q?: ?string, categorieConsentite?: ?list<string>, ordina?: ?string, verso?: ?string, ids?: list<string>, anno?: ?string, emittente?: ?string, tag?: ?string, tipo?: ?string} $filters
     *
     * @return array{items: list<Documento>, total: int}
     */
    public function search(Tenant $tenant, array $filters, int $page, int $perPage): array
    {
        $qb = $this->filtrati($tenant, $filters);

        $total = (int) (clone $qb)->select('COUNT(DISTINCT d.id)')->getQuery()->getSingleScalarResult();
        $verso = 'asc' === ($filters['verso'] ?? null) ? 'ASC' : 'DESC';
        $items = $qb->addSelect('c')
            // Senza data del documento si usa quella di caricamento. DQL non accetta COALESCE in ORDER BY.
            ->addSelect('COALESCE(d.dataDocumento, d.createdAt) AS HIDDEN dataOrdine')
            ->innerJoin('d.categoria', 'c')
            ->orderBy(match ($filters['ordina'] ?? null) {
                'titolo' => 'LOWER(d.titolo)',
                'data' => 'dataOrdine',
                default => 'd.createdAt',
            }, $verso)
            ->addOrderBy('d.id', $verso)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @param array<string, mixed> $filters */
    private function filtrati(Tenant $tenant, array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid');

        // Accesso d'emergenza limitato: solo le categorie concesse (come il voter).
        if (\is_array($filters['categorieConsentite'] ?? null)) {
            $ids = array_values(array_filter($filters['categorieConsentite'], static fn ($id) => \is_string($id) && Uuid::isValid($id)));
            if ([] === $ids) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('IDENTITY(d.categoria) IN (:consentite)')->setParameter('consentite', $ids);
            }
        }

        if (!empty($filters['categoriaId']) && Uuid::isValid($filters['categoriaId'])) {
            $qb->andWhere('d.categoria = :categoria')->setParameter('categoria', Uuid::fromString($filters['categoriaId']), 'uuid');
        }
        if (\array_key_exists('ids', $filters)) {
            $ids = array_values(array_filter($filters['ids'], static fn (string $id) => Uuid::isValid($id)));
            if ([] === $ids) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('d.id IN (:ids)')->setParameter('ids', $ids);
            }
        }
        if (!empty($filters['membroId']) && Uuid::isValid($filters['membroId'])) {
            $qb->innerJoin('d.membri', 'mf')->andWhere('mf.id = :membro')->setParameter('membro', Uuid::fromString($filters['membroId']), 'uuid');
        }
        if (!empty($filters['q'])) {
            // Il testo OCR è in chiaro solo per i documenti non cifrati: su quelli cifrati si cerca nel titolo.
            $qb->andWhere('LOWER(d.titolo) LIKE :q OR (d.cifrato = false AND LOWER(d.ocrText) LIKE :q)')
                ->setParameter('q', '%'.mb_strtolower(trim($filters['q'])).'%');
        }

        $anno = $filters['anno'] ?? null;
        if (IndiceDocumenti::NESSUNO === $anno) {
            $qb->andWhere('d.dataDocumento IS NULL');
        } elseif (\is_string($anno) && 1 === preg_match('/^\d{4}$/', $anno)) {
            $qb->andWhere('d.dataDocumento >= :annoDa AND d.dataDocumento < :annoA')
                ->setParameter('annoDa', new \DateTimeImmutable("{$anno}-01-01"), 'date_immutable')
                ->setParameter('annoA', new \DateTimeImmutable(((int) $anno + 1).'-01-01'), 'date_immutable');
        }
        $this->filtraIndice($qb, $tenant, 'emittente', $filters['emittente'] ?? null);
        $this->filtraIndice($qb, $tenant, 'tag', $filters['tag'] ?? null);
        if (!empty($filters['tipo'])) {
            // "File": ciò che non rientra in nessun tipo noto, cioè un MIME non più ammesso.
            'File' === $filters['tipo']
                ? $qb->andWhere('d.mimeType NOT IN (:mime)')->setParameter('mime', array_keys(UploadPolicy::ALLOWED))
                : $qb->andWhere('d.mimeType IN (:mime)')->setParameter('mime', UploadPolicy::mimeDelTipo((string) $filters['tipo']) ?: ['-']);
        }

        return $qb;
    }

    /**
     * Id dei documenti che rispettano i filtri (gli stessi di search, restrizioni comprese).
     *
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    public function idsFiltrati(Tenant $tenant, array $filters): array
    {
        return array_map(static fn ($id) => (string) $id, $this->filtrati($tenant, $filters)->select('DISTINCT d.id')->getQuery()->getSingleColumnResult());
    }

    /** @param 'emittente'|'tag' $campo */
    private function filtraIndice(QueryBuilder $qb, Tenant $tenant, string $campo, mixed $valore): void
    {
        if (!\is_string($valore) || '' === $valore) {
            return;
        }
        $senza = IndiceDocumenti::NESSUNO === $valore;
        $ids = $this->indice->documentiCon($tenant, $campo, $senza ? null : $valore);
        if ([] === $ids) {
            if (!$senza) {
                $qb->andWhere('1 = 0');
            }

            return;
        }
        $qb->andWhere(\sprintf('d.id %s (:indice_%s)', $senza ? 'NOT IN' : 'IN', $campo))->setParameter('indice_'.$campo, $ids);
    }

    /**
     * Documenti dello stesso tenant con lo stesso contenuto (per SHA-256 o HMAC).
     *
     * @param list<string> $hashes
     *
     * @return list<Documento>
     */
    public function findPossibleDuplicates(Tenant $tenant, array $hashes, ?Uuid $excludeId = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.hashSha256 IN (:hashes)')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setParameter('hashes', $hashes)
            ->setMaxResults(10);
        if (null !== $excludeId) {
            $qb->andWhere('d.id != :id')->setParameter('id', $excludeId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    public function totalBytes(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.dimensioneBytes), 0)')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Esiste già nel tenant un documento con questo contenuto (HMAC dell'MD5)? */
    public function existsWithMd5Fingerprint(Tenant $tenant, string $md5Fingerprint): bool
    {
        return null !== $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.hashMd5 = :h')
            ->setParameter('tenant', $tenant->getId(), 'uuid')
            ->setParameter('h', $md5Fingerprint)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Questa versione del file esterno è già stata importata dalla sorgente? */
    public function existsImportedVersion(ImportSource $source, string $fileId, string $versione): bool
    {
        return null !== $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.importSource = :source')
            ->andWhere('d.sorgenteFileId = :file')
            ->andWhere('d.sorgenteVersione = :versione')
            ->setParameter('source', $source->getId(), 'uuid')
            ->setParameter('file', $fileId)
            ->setParameter('versione', $versione)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Documenti cifrati con una versione di master key diversa da quella indicata. @return iterable<Documento> */
    public function iterateEncryptedNotAtVersion(int $version): iterable
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.cifrato = true')
            ->andWhere('d.keyVersion != :v')
            ->setParameter('v', $version)
            ->getQuery()
            ->toIterable();
    }
}
