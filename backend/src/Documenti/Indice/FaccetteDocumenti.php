<?php

namespace App\Documenti\Indice;

use App\Documenti\Repository\DocumentoRepository;
use App\Documenti\UploadPolicy;
use App\Tenancy\Entity\Tenant;
use Doctrine\DBAL\Connection;

/**
 * Quanti documenti ci sono per anno, mittente, tag, categoria, persona e tipo
 * di file, tra quelli che rispettano i filtri: alimenta il pannello dei filtri
 * e la vista a gruppi.
 *
 * I documenti si scelgono con gli stessi filtri della lista, restrizioni
 * comprese (DocumentoRepository::idsFiltrati): i conteggi non rivelano documenti
 * che l'utente non può vedere. Ogni gruppo ignora il proprio filtro, così dopo
 * aver scelto "2024" restano visibili anche gli altri anni.
 *
 * I documenti cifrati non hanno indice: finiscono in "Senza mittente" e "Senza tag".
 */
class FaccetteDocumenti
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentoRepository $documenti,
    ) {
    }

    /**
     * @param array<string, mixed> $filtri come DocumentoRepository::search
     *
     * @return array{totale: int, anni: list<array<string, mixed>>, emittenti: list<array<string, mixed>>, tag: list<array<string, mixed>>, categorie: list<array<string, mixed>>, persone: list<array<string, mixed>>, tipi: list<array<string, mixed>>}
     */
    public function calcola(Tenant $tenant, array $filtri): array
    {
        $cache = [];
        // Senza il filtro del gruppo; se il filtro non è attivo l'insieme è sempre lo stesso.
        $ids = function (string $campo) use ($tenant, $filtri, &$cache): array {
            $chiave = empty($filtri[$campo]) ? '' : $campo;

            return $cache[$chiave] ??= $this->documenti->idsFiltrati($tenant, array_diff_key($filtri, [$campo => true]));
        };

        return [
            'totale' => \count($ids('')),
            'anni' => $this->anni($ids('anno')),
            'emittenti' => $this->emittenti($ids('emittente')),
            'tag' => $this->tag($ids('tag')),
            'categorie' => $this->categorie($ids('categoriaId')),
            'persone' => $this->persone($ids('membroId')),
            'tipi' => $this->tipi($ids('tipo')),
        ];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>> dall'anno più recente, senza data in fondo
     */
    private function anni(array $ids): array
    {
        $righe = $this->righe('SELECT CAST(EXTRACT(YEAR FROM d.data_documento) AS INT) AS valore, COUNT(*) AS n FROM documento d WHERE d.id = ANY(CAST(:ids AS uuid[])) GROUP BY 1 ORDER BY 1 DESC NULLS LAST', $ids);

        return array_map(static fn (array $r) => null === $r['valore']
            ? self::voce(IndiceDocumenti::NESSUNO, 'Senza data', $r['n'])
            : self::voce((string) $r['valore'], (string) $r['valore'], $r['n']), $righe);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function emittenti(array $ids): array
    {
        $chiave = IndiceDocumenti::CHIAVE_EMITTENTE;
        $voci = [];
        foreach ($this->righe(<<<SQL
            SELECT COALESCE({$chiave}, '') AS valore, mode() WITHIN GROUP (ORDER BY i.dati->>'emittente') AS etichetta, COUNT(*) AS n
            FROM documento d LEFT JOIN documento_indice i ON i.documento_id = d.id
            WHERE d.id = ANY(CAST(:ids AS uuid[]))
            GROUP BY 1
            SQL, $ids) as $r) {
            $voci[] = '' === $r['valore'] ? self::voce(IndiceDocumenti::NESSUNO, 'Senza mittente', $r['n']) : self::voce((string) $r['valore'], (string) $r['etichetta'], $r['n']);
        }

        return self::ordinate($voci);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function tag(array $ids): array
    {
        $voci = array_map(static fn (array $r) => self::voce((string) $r['valore'], (string) $r['valore'], $r['n']), $this->righe(<<<'SQL'
            SELECT t AS valore, COUNT(DISTINCT i.documento_id) AS n
            FROM documento_indice i CROSS JOIN LATERAL jsonb_array_elements_text(i.tag) t
            WHERE i.documento_id = ANY(CAST(:ids AS uuid[]))
            GROUP BY 1
            SQL, $ids));
        $conTag = (int) $this->db->fetchOne('SELECT COUNT(*) FROM documento_indice i WHERE i.documento_id = ANY(CAST(:ids AS uuid[])) AND jsonb_array_length(i.tag) > 0', ['ids' => self::elenco($ids)]);
        if (\count($ids) > $conTag) {
            $voci[] = self::voce(IndiceDocumenti::NESSUNO, 'Senza tag', \count($ids) - $conTag);
        }

        return self::ordinate($voci);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function categorie(array $ids): array
    {
        return self::ordinate(array_map(static fn (array $r) => self::voce((string) $r['valore'], (string) $r['etichetta'], $r['n']), $this->righe(
            'SELECT c.id AS valore, c.nome AS etichetta, COUNT(*) AS n FROM documento d JOIN categoria c ON c.id = d.categoria_id WHERE d.id = ANY(CAST(:ids AS uuid[])) GROUP BY c.id, c.nome',
            $ids,
        )));
    }

    /**
     * Il nome delle persone lo conosce già l'app: qui solo id e conteggio.
     *
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function persone(array $ids): array
    {
        return self::ordinate(array_map(static fn (array $r) => self::voce((string) $r['valore'], (string) $r['valore'], $r['n']), $this->righe(
            'SELECT m.membro_famiglia_id AS valore, COUNT(*) AS n FROM documento_membro_famiglia m WHERE m.documento_id = ANY(CAST(:ids AS uuid[])) GROUP BY 1',
            $ids,
        )));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function tipi(array $ids): array
    {
        $conteggi = [];
        foreach ($this->righe('SELECT d.mime_type AS valore, COUNT(*) AS n FROM documento d WHERE d.id = ANY(CAST(:ids AS uuid[])) GROUP BY 1', $ids) as $r) {
            $tipo = UploadPolicy::tipo((string) $r['valore']);
            $conteggi[$tipo] = ($conteggi[$tipo] ?? 0) + (int) $r['n'];
        }

        return self::ordinate(array_map(static fn (string $tipo, int $n) => self::voce($tipo, $tipo, $n), array_keys($conteggi), array_values($conteggi)));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function righe(string $sql, array $ids): array
    {
        return $this->db->fetchAllAssociative($sql, ['ids' => self::elenco($ids)]);
    }

    /** @param list<string> $ids */
    private static function elenco(array $ids): string
    {
        // Un solo parametro: gli id possono essere migliaia.
        return '{'.implode(',', $ids).'}';
    }

    /** @return array{valore: string, etichetta: string, n: int} */
    private static function voce(string $valore, string $etichetta, mixed $n): array
    {
        return ['valore' => $valore, 'etichetta' => $etichetta, 'n' => (int) $n];
    }

    /**
     * Dalle voci più numerose; "senza…" sempre in fondo.
     *
     * @param list<array{valore: string, etichetta: string, n: int}> $voci
     *
     * @return list<array{valore: string, etichetta: string, n: int}>
     */
    private static function ordinate(array $voci): array
    {
        usort($voci, static fn (array $a, array $b) => [IndiceDocumenti::NESSUNO === $a['valore'], $b['n'], $a['etichetta']] <=> [IndiceDocumenti::NESSUNO === $b['valore'], $a['n'], $b['etichetta']]);

        return $voci;
    }
}
