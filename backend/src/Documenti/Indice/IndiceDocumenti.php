<?php

namespace App\Documenti\Indice;

use App\Documenti\Entity\Documento;
use App\Tenancy\Entity\Tenant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Tabelle documento_indice e documento_embedding (SQL diretto: Doctrine non
 * conosce il tipo "vector" di pgvector).
 *
 * Contengono SOLO documenti non cifrati: dati estratti e vettori rivelano il
 * contenuto, quindi un documento che diventa cifrato perde l'indice
 * (DocumentService::encryptExisting). Ogni lettura è limitata a un tenant.
 */
class IndiceDocumenti
{
    private const CAMPI_DATI = ['emittente', 'data', 'importo', 'numero', 'intestatario'];

    /** Valore dei filtri per "senza mittente", "senza tag", "senza data". */
    public const NESSUNO = '__nessuno__';

    /**
     * Chiave del mittente: lo stesso ente scritto in modi diversi ("ENEL ENERGIA S.P.A.",
     * "Enel Energia spa") è un mittente solo. Maiuscole, punteggiatura e forma societaria non contano.
     */
    public const CHIAVE_EMITTENTE = <<<'SQL'
        btrim(regexp_replace(regexp_replace(replace(lower(i.dati->>'emittente'), '.', ''), '[^[:alnum:]]+', ' ', 'g'), '( |^)(srl|srls|spa|sas|snc)\s*$', ''))
        SQL;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Sostituisce l'indice del documento. Da chiamare dentro la transazione che
     * blocca il documento (DocumentService::aggiornaBloccato), dopo aver
     * ricontrollato che non sia cifrato.
     *
     * @param list<list<float>> $vettori una riga per parte del testo
     */
    public function salva(Documento $documento, DatiEstratti $dati, ?string $modelloDati, ?string $modelloEmbedding, array $vettori): void
    {
        if ($documento->isCifrato()) {
            throw new \LogicException('Un documento cifrato non si indicizza.');
        }
        $id = $documento->getId()->toRfc4122();
        $tenant = $documento->getTenant()->getId()->toRfc4122();
        $json = static fn (array $v) => json_encode($v, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        $this->db->executeStatement(<<<'SQL'
            INSERT INTO documento_indice (documento_id, tenant_id, dati, tag, riferimenti, modello_dati, modello_embedding, aggiornato_il)
            VALUES (:id, :tenant, CAST(:dati AS JSONB), CAST(:tag AS JSONB), CAST(:rif AS JSONB), :md, :me, :ora)
            ON CONFLICT (documento_id) DO UPDATE SET
                dati = EXCLUDED.dati, tag = EXCLUDED.tag, riferimenti = EXCLUDED.riferimenti,
                modello_dati = EXCLUDED.modello_dati, modello_embedding = EXCLUDED.modello_embedding,
                aggiornato_il = EXCLUDED.aggiornato_il
            SQL, [
            'id' => $id,
            'tenant' => $tenant,
            'dati' => $json($dati->dati()),
            'tag' => $json($dati->tag),
            'rif' => $json($dati->riferimenti),
            'md' => $modelloDati,
            'me' => [] === $vettori ? null : $modelloEmbedding,
            'ora' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->db->executeStatement('DELETE FROM documento_embedding WHERE documento_id = :id', ['id' => $id]);
        foreach ($vettori as $parte => $vettore) {
            $this->db->executeStatement(
                'INSERT INTO documento_embedding (documento_id, parte, tenant_id, modello, embedding) VALUES (:id, :parte, :tenant, :modello, CAST(:v AS vector))',
                ['id' => $id, 'parte' => $parte, 'tenant' => $tenant, 'modello' => (string) $modelloEmbedding, 'v' => self::vettore($vettore)],
            );
        }
    }

    public function elimina(Documento $documento): void
    {
        // Il documento resta: vettori e dati estratti si cancellano esplicitamente.
        $id = $documento->getId()->toRfc4122();
        $this->db->executeStatement('DELETE FROM documento_embedding WHERE documento_id = :id', ['id' => $id]);
        $this->db->executeStatement('DELETE FROM documento_indice WHERE documento_id = :id', ['id' => $id]);
    }

    /**
     * @return array{dati: array<string, ?string>, tag: list<string>, riferimenti: list<string>, aggiornatoIl: string}|null
     */
    public function leggi(Documento $documento): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT dati, tag, riferimenti, aggiornato_il FROM documento_indice WHERE documento_id = :id AND tenant_id = :tenant',
            ['id' => $documento->getId()->toRfc4122(), 'tenant' => $documento->getTenant()->getId()->toRfc4122()],
        );
        if (false === $row) {
            return null;
        }

        $dati = self::decodifica($row['dati']);

        return [
            // JSONB non conserva l'ordine delle chiavi: sempre lo stesso ordine nella risposta.
            'dati' => array_map(static fn (string $k) => \is_string($dati[$k] ?? null) ? $dati[$k] : null, array_combine(self::CAMPI_DATI, self::CAMPI_DATI)),
            'tag' => array_values(self::decodifica($row['tag'])),
            'riferimenti' => array_values(self::decodifica($row['riferimenti'])),
            'aggiornatoIl' => (new \DateTimeImmutable((string) $row['aggiornato_il']))->format(\DATE_ATOM),
        ];
    }

    /**
     * Documenti del tenant più vicini al vettore (distanza coseno: 0 = uguali, 2 = opposti).
     * Per ogni documento vale la sua parte più vicina.
     *
     * @param list<float> $vettore
     *
     * @return list<array{id: string, distanza: float}> dal più vicino
     */
    public function cerca(Tenant $tenant, array $vettore, string $modello, int $limite): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT documento_id, MIN(embedding <=> CAST(:v AS vector)) AS distanza
            FROM documento_embedding
            WHERE tenant_id = :tenant AND modello = :modello
            GROUP BY documento_id
            ORDER BY distanza, documento_id
            LIMIT :limite
            SQL, ['v' => self::vettore($vettore), 'tenant' => $tenant->getId()->toRfc4122(), 'modello' => $modello, 'limite' => $limite], ['limite' => ParameterType::INTEGER]);

        return self::risultati($rows);
    }

    /**
     * Altri documenti del tenant più vicini a questo (coppia di parti più vicina).
     *
     * @return list<array{id: string, distanza: float}>
     */
    public function vicini(Documento $documento, int $limite): array
    {
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT altro.documento_id, MIN(questo.embedding <=> altro.embedding) AS distanza
            FROM documento_embedding questo
            JOIN documento_embedding altro
              ON altro.tenant_id = questo.tenant_id AND altro.modello = questo.modello AND altro.documento_id <> questo.documento_id
            WHERE questo.documento_id = :id AND questo.tenant_id = :tenant
            GROUP BY altro.documento_id
            ORDER BY distanza, altro.documento_id
            LIMIT :limite
            SQL, ['id' => $documento->getId()->toRfc4122(), 'tenant' => $documento->getTenant()->getId()->toRfc4122(), 'limite' => $limite], ['limite' => ParameterType::INTEGER]);

        return self::risultati($rows);
    }

    /**
     * Altri documenti del tenant con almeno uno dei riferimenti (stessa targa, stesso POD...).
     *
     * @param list<string> $riferimenti
     *
     * @return list<array{id: string, riferimento: string}>
     */
    public function conRiferimenti(Documento $documento, array $riferimenti, int $limite): array
    {
        if ([] === $riferimenti) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT documento_id, riferimenti
            FROM documento_indice
            WHERE tenant_id = :tenant AND documento_id <> :id AND jsonb_exists_any(riferimenti, CAST(ARRAY[:rif] AS TEXT[]))
            ORDER BY aggiornato_il DESC, documento_id
            LIMIT :limite
            SQL, [
            'tenant' => $documento->getTenant()->getId()->toRfc4122(),
            'id' => $documento->getId()->toRfc4122(),
            'rif' => $riferimenti,
            'limite' => $limite,
        ], ['rif' => ArrayParameterType::STRING, 'limite' => ParameterType::INTEGER]);

        $out = [];
        foreach ($rows as $row) {
            $comuni = array_values(array_intersect($riferimenti, self::decodifica($row['riferimenti'])));
            $out[] = ['id' => Uuid::fromString((string) $row['documento_id'])->toRfc4122(), 'riferimento' => $comuni[0] ?? ''];
        }

        return $out;
    }

    /**
     * Documenti del tenant con almeno uno di questi tag (parole minuscole).
     *
     * @param list<string> $parole
     *
     * @return list<string> id
     */
    public function conTag(Tenant $tenant, array $parole, int $limite): array
    {
        if ([] === $parole) {
            return [];
        }
        $ids = $this->db->fetchFirstColumn(<<<'SQL'
            SELECT documento_id FROM documento_indice
            WHERE tenant_id = :tenant AND jsonb_exists_any(tag, CAST(ARRAY[:parole] AS TEXT[]))
            ORDER BY aggiornato_il DESC, documento_id
            LIMIT :limite
            SQL, ['tenant' => $tenant->getId()->toRfc4122(), 'parole' => $parole, 'limite' => $limite],
            ['parole' => ArrayParameterType::STRING, 'limite' => ParameterType::INTEGER]);

        return array_map(static fn ($id) => Uuid::fromString((string) $id)->toRfc4122(), $ids);
    }

    /**
     * Documenti del tenant con quel mittente (chiave) o quel tag; con $valore
     * null, quelli che ne hanno uno qualsiasi.
     *
     * @param 'emittente'|'tag' $campo
     *
     * @return list<string> id
     */
    public function documentiCon(Tenant $tenant, string $campo, ?string $valore): array
    {
        $chiave = self::CHIAVE_EMITTENTE;
        [$condizione, $params] = match (true) {
            'emittente' === $campo && null === $valore => ["COALESCE({$chiave}, '') <> ''", []],
            'emittente' === $campo => ["{$chiave} = :valore", ['valore' => $valore]],
            null === $valore => ['jsonb_array_length(i.tag) > 0', []],
            default => ['jsonb_exists(i.tag, :valore)', ['valore' => $valore]],
        };

        return array_map(
            static fn ($id) => (string) $id,
            $this->db->fetchFirstColumn("SELECT i.documento_id FROM documento_indice i WHERE i.tenant_id = :tenant AND {$condizione}", ['tenant' => $tenant->getId()->toRfc4122()] + $params),
        );
    }

    /**
     * Documenti non cifrati con il testo OCR pronto, da (ri)indicizzare. Con
     * $tenant solo quelli del tenant, e con $categorieConsentite (accesso
     * d'emergenza limitato) solo quelli di quelle categorie.
     *
     * @param list<string>|null $categorieConsentite
     *
     * @return list<string> id
     */
    public function daIndicizzare(bool $tutti, ?string $modelloEmbedding, ?Tenant $tenant = null, ?array $categorieConsentite = null): array
    {
        [$ambito, $params, $tipi] = self::ambito($tenant, $categorieConsentite);
        $sql = "SELECT d.id FROM documento d LEFT JOIN documento_indice i ON i.documento_id = d.id WHERE d.cifrato = false AND d.ocr_status = 'completato'{$ambito}";
        if (!$tutti) {
            $sql .= ' AND '.self::daFare($modelloEmbedding, $params);
        }

        return array_map(static fn ($id) => (string) $id, $this->db->fetchFirstColumn($sql.' ORDER BY d.created_at, d.id', $params, $tipi));
    }

    /**
     * Avanzamento dell'analisi dei documenti del tenant (solo conteggi, nessun contenuto).
     * "daAnalizzare" usa la stessa regola di daIndicizzare: è ciò che rimette in coda
     * app:documenti:indicizza o il pulsante "Analizza i mancanti".
     *
     * @param list<string>|null $categorieConsentite
     *
     * @return array{totale: int, analizzati: int, daAnalizzare: int, testoInLettura: int, senzaTesto: int, cifrati: int}
     */
    public function avanzamento(Tenant $tenant, ?array $categorieConsentite, ?string $modelloEmbedding): array
    {
        [$ambito, $params, $tipi] = self::ambito($tenant, $categorieConsentite);
        $daFare = self::daFare($modelloEmbedding, $params);
        $pronto = "NOT d.cifrato AND d.ocr_status = 'completato'";
        $row = $this->db->fetchAssociative(<<<SQL
            SELECT
                COUNT(*) AS totale,
                COUNT(*) FILTER (WHERE {$pronto} AND NOT ({$daFare})) AS analizzati,
                COUNT(*) FILTER (WHERE {$pronto} AND {$daFare}) AS da_analizzare,
                COUNT(*) FILTER (WHERE NOT d.cifrato AND d.ocr_status = 'in_coda') AS testo_in_lettura,
                COUNT(*) FILTER (WHERE NOT d.cifrato AND d.ocr_status IN ('fallito', 'non_applicabile')) AS senza_testo,
                COUNT(*) FILTER (WHERE d.cifrato) AS cifrati
            FROM documento d
            LEFT JOIN documento_indice i ON i.documento_id = d.id
            WHERE true{$ambito}
            SQL, $params, $tipi) ?: [];

        return [
            'totale' => (int) ($row['totale'] ?? 0),
            'analizzati' => (int) ($row['analizzati'] ?? 0),
            'daAnalizzare' => (int) ($row['da_analizzare'] ?? 0),
            'testoInLettura' => (int) ($row['testo_in_lettura'] ?? 0),
            'senzaTesto' => (int) ($row['senza_testo'] ?? 0),
            'cifrati' => (int) ($row['cifrati'] ?? 0),
        ];
    }

    /**
     * Condizione "da (ri)indicizzare" su d (documento) e i (documento_indice):
     * mai indicizzato, o indicizzato senza vettori o con un altro modello di
     * embedding (cambio di OLLAMA_EMBED_MODEL). Un testo vuoto non ha vettori
     * per definizione: con l'indice salvato è finito, non si rimette in coda.
     *
     * @param array<string, mixed> $params riceve :modello_embedding
     */
    private static function daFare(?string $modelloEmbedding, array &$params): string
    {
        if (null === $modelloEmbedding) {
            return 'i.documento_id IS NULL';
        }
        $params['modello_embedding'] = $modelloEmbedding;

        return "(i.documento_id IS NULL OR (i.modello_embedding IS DISTINCT FROM :modello_embedding AND COALESCE(d.ocr_text, '') ~ '[^[:space:]]'))";
    }

    /**
     * Filtro per tenant e categorie consentite (lista vuota = nessun documento).
     *
     * @param list<string>|null $categorieConsentite
     *
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private static function ambito(?Tenant $tenant, ?array $categorieConsentite): array
    {
        $sql = '';
        $params = [];
        $tipi = [];
        if (null !== $tenant) {
            $sql .= ' AND d.tenant_id = :tenant';
            $params['tenant'] = $tenant->getId()->toRfc4122();
        }
        if (null !== $categorieConsentite) {
            $ids = array_values(array_filter($categorieConsentite, static fn (string $id) => Uuid::isValid($id)));
            if ([] === $ids) {
                $sql .= ' AND 1 = 0';
            } else {
                $sql .= ' AND d.categoria_id IN (:categorie)';
                $params['categorie'] = $ids;
                $tipi['categorie'] = ArrayParameterType::STRING;
            }
        }

        return [$sql, $params, $tipi];
    }

    /** @param list<float> $vettore */
    private static function vettore(array $vettore): string
    {
        foreach ($vettore as $x) {
            if (!is_finite($x)) {
                throw new \InvalidArgumentException('Vettore con valori non finiti.');
            }
        }

        return json_encode($vettore, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, distanza: float}>
     */
    private static function risultati(array $rows): array
    {
        return array_map(static fn (array $r) => ['id' => Uuid::fromString((string) $r['documento_id'])->toRfc4122(), 'distanza' => (float) $r['distanza']], $rows);
    }

    /** @return array<mixed> */
    private static function decodifica(mixed $json): array
    {
        $v = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($v) ? $v : [];
    }
}
