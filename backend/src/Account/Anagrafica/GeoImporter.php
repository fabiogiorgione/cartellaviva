<?php

namespace App\Account\Anagrafica;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Importa stati e comuni dal dataset devnix/belfiore-code (lo stesso usato da
 * Idempotente: ricarica le tabelle geo_*.
 *
 * Tutti i comuni attivi, più quelli soppressi il cui codice
 * Belfiore + nome non coincide con un comune attivo.
 */
final class GeoImporter
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%/vendor/devnix/belfiore-code/dist')]
        private readonly string $datasetDir,
    ) {
    }

    /** @return array{countries: int, comuni: int} */
    public function import(): array
    {
        $countryRows = [];
        foreach ($this->read('regions.json') as $c) {
            $istat = (int) $c['istat_id'];
            $registry = $c['registry_code'] ?? null;
            $countryRows[$istat] ??= [
                $istat,
                $c['name_it'],
                $c['name_en'] ?: null,
                \in_array($registry, [null, '', 'n.d.', 'ND'], true) ? null : $registry,
                ($c['iso-3166-1-alpha-2'] ?? '') ?: null,
                $c['continent_name_it'] ?: null,
            ];
        }

        $cities = $this->read('cities.json');
        $active = [];
        foreach ($cities as $c) {
            if ('active' === $c['status'] && 'ND' !== $c['registry_code']) {
                $active[$c['registry_code'].'|'.$c['name_it']] = true;
            }
        }
        $comuneRows = [];
        foreach ($cities as $c) {
            if ('ND' === $c['registry_code'] || ('active' !== $c['status'] && isset($active[$c['registry_code'].'|'.$c['name_it']]))) {
                continue;
            }
            $comuneRows[] = [
                (int) $c['id'], $c['istat_id'], $c['registry_code'], $c['name_it'],
                ($c['provincial_code'] ?? '') ?: null, $c['status'], $c['institution_date'], $c['end_date'],
            ];
        }

        $this->connection->transactional(function (Connection $db) use ($countryRows, $comuneRows): void {
            $db->executeStatement('DELETE FROM geo_country');
            $this->bulkInsert($db, 'geo_country', ['istat_id', 'name_it', 'name_en', 'registry_code', 'iso_alpha2', 'continent_name_it'], array_values($countryRows));
            $db->executeStatement('DELETE FROM geo_comune');
            $this->bulkInsert($db, 'geo_comune', ['id', 'istat_id', 'registry_code', 'name_it', 'provincial_code', 'status', 'institution_date', 'end_date'], $comuneRows);
        });

        return ['countries' => \count($countryRows), 'comuni' => \count($comuneRows)];
    }

    /**
     * INSERT multi-riga a blocchi: poche query anche con ~13.000 comuni.
     *
     * @param list<string>                $columns
     * @param list<list<string|int|null>> $rows
     */
    private function bulkInsert(Connection $db, string $table, array $columns, array $rows): void
    {
        $placeholders = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->executeStatement(
                sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($chunk), $placeholders))),
                array_merge(...$chunk),
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function read(string $file): array
    {
        $data = json_decode((string) file_get_contents($this->datasetDir.'/'.$file), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : [];
    }
}
