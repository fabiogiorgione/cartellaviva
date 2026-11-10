<?php

namespace App\Documenti\Classificazione;

use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Categoria;
use App\Documenti\Entity\Documento;
use App\Documenti\Enum\OcrStatus;
use App\Documenti\Indice\IndiceDocumenti;
use App\Documenti\Repository\CategoriaRepository;
use App\Ente\RisposteService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Proposta di categoria per un documento (wizard "Proponi categoria con l'AI").
 * Non cambia nulla: la proposta la approva una persona, poi si sposta con
 * l'operazione di gruppo.
 *
 * 1. Tra le categorie esistenti (con qualche titolo di esempio per ognuna) e le
 *    categorie nuove già proposte in questo giro ($nuove), così documenti simili
 *    finiscono nella stessa categoria nuova.
 * 2. Verifica sì/no della scelta contro una descrizione della categoria (in cache):
 *    un modello piccolo forza spesso il documento nella categoria "meno lontana"
 *    (un volo in "Veicoli").
 * 3. Se nessuna va bene, e c'è il testo, il nome di una categoria nuova.
 *
 * Come la classificazione degli import, il testo di un documento cifrato si
 * decifra solo in memoria e va solo al modello locale. Senza testo (OCR non
 * ancora fatto, ZIP...) si decide dal titolo e dal nome del file, e non si
 * propongono categorie nuove.
 */
final class PropostaCategoria
{
    private const ESEMPI = 3;

    /** Parole dei nomi di file generati da scanner, telefoni e firme digitali. */
    private const PAROLE_VUOTE = ['scan', 'scansione', 'scansionato', 'documento', 'document', 'file', 'image', 'immagine', 'foto', 'photo', 'signed', 'firmato', 'copia', 'copy', 'nuovo', 'senza', 'titolo', 'untitled', 'pagina', 'page'];

    public function __construct(
        private readonly ClassifierInterface $classifier,
        private readonly CategoriaRepository $categorie,
        private readonly DocumentService $documents,
        private readonly IndiceDocumenti $indice,
        private readonly DocumentAudit $audit,
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->classifier->isEnabled();
    }

    /**
     * @param list<string> $nuove   nomi delle categorie nuove già proposte per gli altri documenti
     * @param list<string> $escludi id dei documenti da non usare come esempi (quelli in revisione
     *                              insieme: spesso stanno tutti nella categoria sbagliata)
     *
     * @return array{tipo: 'esistente'|'nuova'|'nessuna', categoriaId?: string, nome?: string, sensibile?: bool|null, icona?: string|null, motivo?: string, fonte: 'testo'|'nome'}
     */
    public function proponi(Documento $documento, array $nuove, array $escludi = []): array
    {
        // Non si propongono le categorie "di servizio": in attesa di classificazione e copie consegnate agli enti.
        $candidate = array_values(array_filter($this->categorie->findUsableIn($documento->getTenant()),
            static fn (Categoria $c) => !$c->isDaCategorizzare() && !($c->isSystem() && RisposteService::CATEGORIA === $c->getSlug())));
        $perNome = [];
        foreach ($candidate as $c) {
            $chiave = mb_strtolower($c->getNome());
            // A parità di nome (sistema e famiglia) vince la sensibile, come nella classificazione degli import.
            if (!isset($perNome[$chiave]) || $c->isSensibile()) {
                $perNome[$chiave] = $c;
            }
        }
        $nuove = array_values(array_filter(array_unique(array_filter(array_map(NuovaCategoria::normalizzaNome(...), $nuove))), static fn (string $n) => !isset($perNome[mb_strtolower($n)])));

        $testo = OcrStatus::COMPLETATO === $documento->getOcrStatus() ? trim((string) $this->documents->readOcrText($documento)) : '';
        $fonte = '' === $testo ? 'nome' : 'testo';
        $indizi = $this->indizi($documento);

        $esito = ['tipo' => 'nessuna', 'motivo' => 'Nessuna categoria sembra adatta.', 'fonte' => $fonte];
        $modello = null;
        if ([] !== $perNome || [] !== $nuove) {
            $nomi = [...array_map(static fn (Categoria $c) => $c->getNome(), array_values($perNome)), ...$nuove];
            $esempi = $this->esempi($documento, $escludi);
            $result = $this->classifier->classify($documento->getNomeFileOriginale(), $testo, $nomi, $esempi, $indizi);
            $modello = $result->modello;
            $scelta = null === $result->categoria ? null : mb_strtolower($result->categoria);
            // Il modello sceglie quasi sempre qualcosa: una domanda sì/no scarta le scelte forzate.
            if (null !== $scelta && !$this->classifier->verifica($documento->getNomeFileOriginale(), $testo, (string) $result->categoria, $this->descrizione($result->modello, (string) $result->categoria, $esempi[(string) $result->categoria] ?? []), $indizi)) {
                $scelta = null;
            }
            if (null !== $scelta && isset($perNome[$scelta])) {
                $esito = ['tipo' => 'esistente', 'categoriaId' => (string) $perNome[$scelta]->getId(), 'nome' => $perNome[$scelta]->getNome(), 'fonte' => $fonte];
            } elseif (null !== $scelta) {
                $esito = ['tipo' => 'nuova', 'nome' => (string) $result->categoria, 'sensibile' => null, 'icona' => null, 'fonte' => $fonte];
            }
        }

        if ('nessuna' === $esito['tipo']) {
            if ('' === $testo) {
                $esito['motivo'] = 'Il testo del documento non è disponibile e dal nome non si capisce.';
            } elseif (null !== ($nuova = $this->classifier->proponiNuova($documento->getNomeFileOriginale(), $testo, $indizi, [...array_map(static fn (Categoria $c) => $c->getNome(), array_values($perNome)), ...$nuove]))) {
                $modello = $nuova->modello;
                $esito = ['tipo' => 'nuova', 'nome' => $nuova->nome, 'sensibile' => $nuova->sensibile, 'icona' => $nuova->icona, 'fonte' => $fonte];
            }
        }
        unset($testo);

        // Di un documento cifrato non si scrive nell'audit nulla di ricavato dal contenuto.
        $this->audit->write($documento, 'documento.categoria_proposta', ['esito' => $esito['tipo'], 'modello' => $modello]
            + ($documento->isCifrato() || 'nessuna' === $esito['tipo'] ? [] : ['proposta' => $esito['nome'] ?? null]));

        return $esito;
    }

    /**
     * Descrizione della categoria per la verifica: una chiamata al modello, poi in
     * cache finché non cambiano modello, nome o esempi (vale per tutto il wizard).
     *
     * @param list<string> $esempi
     */
    private function descrizione(string $modello, string $categoria, array $esempi): string
    {
        $chiave = 'categoria_descrizione_'.hash('xxh128', json_encode([$modello, $categoria, $esempi], \JSON_THROW_ON_ERROR));

        return $this->cache->get($chiave, function (ItemInterface $item) use ($categoria, $esempi): string {
            $item->expiresAfter(7 * 86400);

            return $this->classifier->descriviCategoria($categoria, $esempi);
        });
    }

    /** @return list<string> */
    private function indizi(Documento $documento): array
    {
        $indizi = ['Titolo: '.$documento->getTitolo()];
        // L'emittente aiuta; i tag no: il modello li ripete come argomento e la verifica sbaglia.
        if (!$documento->isCifrato() && null !== ($emittente = $this->indice->leggi($documento)['dati']['emittente'] ?? null)) {
            $indizi[] = 'Emittente: '.$emittente;
        }

        return $indizi;
    }

    /**
     * Titoli dei documenti più recenti di ogni categoria della famiglia: fanno
     * capire al modello cosa la famiglia mette in categorie dal nome generico.
     * Esclusi il documento stesso, quelli in revisione insieme a lui e i titoli
     * che sono solo un nome di file ("DOC_1660607446", "scan 12"): non dicono nulla.
     *
     * @param list<string> $escludi
     *
     * @return array<string, list<string>> nome della categoria => titoli
     */
    private function esempi(Documento $documento, array $escludi): array
    {
        $escludi = array_values(array_filter([$documento->getId()->toRfc4122(), ...$escludi], Uuid::isValid(...)));
        $righe = $this->db->fetchAllAssociative(<<<'SQL'
            SELECT c.nome, d.titolo FROM documento d JOIN categoria c ON c.id = d.categoria_id
            WHERE d.tenant_id = :tenant AND d.id NOT IN (:escludi)
            ORDER BY d.created_at DESC
            LIMIT 500
            SQL, ['tenant' => $documento->getTenant()->getId()->toRfc4122(), 'escludi' => $escludi], ['escludi' => ArrayParameterType::STRING]);
        $esempi = [];
        foreach ($righe as $r) {
            $nome = (string) $r['nome'];
            if (\count($esempi[$nome] ?? []) < self::ESEMPI && self::titoloParlante((string) $r['titolo'])) {
                $esempi[$nome][] = (string) $r['titolo'];
            }
        }

        return $esempi;
    }

    /** Un titolo dice qualcosa se ha almeno una parola vera (4+ lettere, non "scan", "documento"...). */
    public static function titoloParlante(string $titolo): bool
    {
        preg_match_all('/\p{L}{4,}/u', mb_strtolower($titolo), $m);

        return [] !== array_diff($m[0], self::PAROLE_VUOTE);
    }
}
