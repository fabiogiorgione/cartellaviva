<?php

namespace App\Pratiche;

use App\Pratiche\Modello\Campo;
use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\Modello\Requisito;
use App\Tenancy\Entity\MembroFamiglia;
use Psr\Log\LoggerInterface;

/**
 * Dal testo di un documento alle voci proposte per una pratica.
 *
 * 1. Requisiti candidati: categoria del documento o parole chiave nel testo.
 * 2. AI (se attiva) per classificare e leggere i campi del requisito; senza
 *    AI o se non risponde, regole deterministiche (una voce, sul requisito
 *    con le parole chiave nel testo).
 * 3. Ogni valore si ricontrolla sul testo, secondo il tipo del campo:
 *    importi e date devono comparire (la data nell'anno atteso dal
 *    requisito), testi e IBAN devono essere scritti nel testo (tranne i testi
 *    liberi), il tipo deve essere tra quelli del requisito, il pagamento
 *    tracciabile letto dalle regole vale più di quello dell'AI. I campi "solo
 *    utente" (es. i valori della DSU precompilata) l'AI non li tocca.
 */
class EstrattoreVoci
{
    public function __construct(
        private readonly AiVociSuggeritore $ai,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Requisiti di cui il documento può parlare, per parole chiave nel testo o
     * nel nome del file (o per categoria). $vecchio: il documento è più vecchio
     * della finestra della pratica, e valgono solo i requisiti "ancheVecchi".
     *
     * @return list<Requisito>
     */
    public static function candidati(ModelloPratica $modello, ?string $categoriaSlug, string $testo, string $nomeFile = '', bool $vecchio = false): array
    {
        $basso = Requisito::normale($testo);
        $nome = Requisito::nomeNormale($nomeFile);
        $punteggi = [];
        foreach ($modello->requisiti as $r) {
            if ($vecchio && !$r->ancheVecchi) {
                continue;
            }
            $punti = (null !== $categoriaSlug && \in_array($categoriaSlug, $r->categorie, true) ? 1 : 0) + ($r->parlaDi($basso, $nome) ? 2 : 0);
            if ($punti > 0) {
                $punteggi[$r->codice] = $punti;
            }
        }
        arsort($punteggi);

        return array_map(static fn (string $c) => $modello->requisiti[$c], array_keys($punteggi));
    }

    /**
     * @param list<MembroFamiglia> $persone persone della pratica
     *
     * @return array{metodo: string, voci: list<array{requisito: string, persona: ?MembroFamiglia, valori: array<string, string>}>}
     */
    public function estrai(ModelloPratica $modello, ?string $categoriaSlug, string $nomeFile, string $testo, array $persone, ?MembroFamiglia $personaDocumento, bool $vecchio = false): array
    {
        $candidati = self::candidati($modello, $categoriaSlug, $testo, $nomeFile, $vecchio);
        if ([] === $candidati) {
            return ['metodo' => 'regole', 'voci' => []];
        }
        $grezze = null;
        $metodo = 'regole';
        // Senza testo (una foto che l'OCR non ha letto) resta solo il nome del file: niente da chiedere all'AI.
        if ('' !== trim($testo) && $this->ai->isEnabled()) {
            try {
                $grezze = $this->ai->suggerisci($modello->titolo, $candidati, $nomeFile, $testo);
                $metodo = 'ai';
            } catch (\Throwable $e) {
                $this->logger->warning('Pratica: Ollama non disponibile, uso le regole ({reason})', ['reason' => $e->getMessage()]);
            }
        }
        // Senza AI la sola categoria non basta: servono anche le parole chiave nel testo.
        if (null === $grezze) {
            $grezze = $candidati[0]->parlaDi(Requisito::normale($testo), Requisito::nomeNormale($nomeFile)) ? [['requisito' => $candidati[0]->codice]] : [];
        }

        return ['metodo' => $metodo, 'voci' => $this->daGrezze($modello, $categoriaSlug, $testo, $persone, $personaDocumento, $grezze, $nomeFile, $vecchio)];
    }

    /**
     * Voci grezze di un modello (Ollama o quello del telefono) ricontrollate sul
     * testo con le regole deterministiche.
     *
     * @param list<array<string, string>> $grezze
     * @param list<MembroFamiglia>        $persone
     *
     * @return list<array{requisito: string, persona: ?MembroFamiglia, valori: array<string, string>}>
     */
    public function daGrezze(ModelloPratica $modello, ?string $categoriaSlug, string $testo, array $persone, ?MembroFamiglia $personaDocumento, array $grezze, string $nomeFile = '', bool $vecchio = false): array
    {
        $candidati = self::candidati($modello, $categoriaSlug, $testo, $nomeFile, $vecchio);
        $voci = [];
        $viste = [];
        foreach ($grezze as $g) {
            $requisito = $modello->requisito((string) ($g['requisito'] ?? ''));
            if (null === $requisito || !\in_array($requisito, $candidati, true)) {
                continue;
            }
            $voce = $this->verifica($modello, $requisito, $g, $testo, $persone, $personaDocumento, $nomeFile);
            if (null === $voce) {
                continue;
            }
            // Un modello piccolo a volte ripete la stessa voce con un tipo diverso
            // (una per ogni tipo dell'elenco): dallo stesso documento, una sola
            // voce per requisito e importo (o IBAN, targa).
            $chiave = $voce['requisito'].'|'.($voce['valori']['importo'] ?? '').'|'.($voce['valori']['iban'] ?? '').'|'.($voce['valori']['targa'] ?? '');
            if (isset($viste[$chiave])) {
                continue;
            }
            $viste[$chiave] = true;
            $voci[] = $voce;
        }

        return $voci;
    }

    /**
     * @param array<string, string> $g
     * @param list<MembroFamiglia>  $persone
     *
     * @return array{requisito: string, persona: ?MembroFamiglia, valori: array<string, string>}|null
     */
    private function verifica(ModelloPratica $modello, Requisito $r, array $g, string $testo, array $persone, ?MembroFamiglia $personaDocumento, string $nomeFile = ''): ?array
    {
        $valori = [];
        $annoAtteso = $r->annoAtteso($modello);
        $primoImporto = true;
        foreach ($r->campi as $codice => $campo) {
            if ($campo->soloUtente) {
                continue;
            }
            $v = match ($campo->tipo) {
                'importo' => $this->importo($campo, $g[$codice] ?? null, $testo, $primoImporto),
                'data' => self::dataDaAi($g[$codice] ?? '', $testo) ?? (null !== $annoAtteso ? TestoVoci::dataNellAnno($testo, $annoAtteso) : null),
                'scelta' => AiVociSuggeritore::tipoValido($r, $g[$codice] ?? null, $testo, $nomeFile),
                'tracciabile' => TestoVoci::tracciabile($testo) ?? (\in_array($g[$codice] ?? null, ['si', 'no'], true) ? $g[$codice] : 'non_risulta'),
                'iban' => self::ibanDaAi($g[$codice] ?? null, $testo),
                'targa' => self::targaDaAi($g[$codice] ?? null, $testo),
                default => $campo->libero
                    ? self::testoLibero($r, $g[$codice] ?? null)
                    : (TestoVoci::nelTesto($g[$codice] ?? null, $testo) ? mb_substr((string) $g[$codice], 0, 200) : null),
            };
            if ('importo' === $campo->tipo) {
                $primoImporto = false;
            }
            if (null !== $v && '' !== $v) {
                $valori[$codice] = (string) $v;
            }
        }

        if (isset($r->campi['data']) && null !== $annoAtteso) {
            $data = $valori['data'] ?? null;
            // Data di un altro anno, o solo date di altri anni nel testo: non è di questa pratica.
            if (null !== $data && (int) substr($data, 0, 4) !== $annoAtteso) {
                return null;
            }
            if (null === $data && [] !== TestoVoci::date($testo)) {
                return null;
            }
        }
        // Senza il primo importo obbligatorio (la spesa, il saldo) la voce non serve a nulla.
        foreach ($r->campi as $codice => $campo) {
            if ('importo' === $campo->tipo && $campo->obbligatorio && !$campo->soloUtente && !isset($valori[$codice])) {
                return null;
            }
        }

        return ['requisito' => $r->codice, 'persona' => $this->persona($r, $g['persona'] ?? null, $testo, $persone, $personaDocumento, $nomeFile), 'valori' => $valori];
    }

    /**
     * L'importo dell'AI vale solo se compare nel testo; altrimenti le regole:
     * vicino alle etichette del campo, o il totale per il primo importo.
     */
    private function importo(Campo $campo, ?string $ai, string $testo, bool $primo): ?string
    {
        $c = ValoriVoce::centesimi($ai);
        if (null === $c || !TestoVoci::importoNelTesto($c, $testo)) {
            $c = [] !== $campo->etichette ? TestoVoci::importoDopo($testo, $campo->etichette) : ($primo ? TestoVoci::importoTotale($testo) : null);
        }

        return null === $c ? null : ValoriVoce::formatta($c);
    }

    /** L'IBAN dell'AI vale se è valido e nel testo; altrimenti il primo IBAN valido del testo. */
    private static function ibanDaAi(?string $ai, string $testo): ?string
    {
        $nelTesto = TestoVoci::iban($testo);
        $ai = null === $ai ? '' : strtoupper((string) preg_replace('/\s+/', '', $ai));

        return \in_array($ai, $nelTesto, true) ? $ai : ($nelTesto[0] ?? null);
    }

    /**
     * Testo libero dell'AI (descrizione), ripulito; scartato se ripete solo le
     * etichette o la descrizione del requisito (il modello copia l'elenco).
     */
    private static function testoLibero(Requisito $r, ?string $testo): ?string
    {
        $t = trim((string) preg_replace('/[^\p{L}\p{N} \',.\-\/°&()]/u', '', (string) $testo));
        if ('' === $t) {
            return null;
        }
        $compatto = static fn (string $s): string => (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($s));
        $c = $compatto($t);
        $d = $compatto($r->descrizione);
        if ('' !== $d && (str_contains($c, $d) || str_contains($d, $c))) {
            return null;
        }
        foreach ([$r->etichetta, ...array_map(static fn ($x) => $x->etichetta, $r->tipi)] as $etichetta) {
            if (str_contains($compatto($etichetta), $c)) {
                return null;
            }
        }

        return mb_substr($t, 0, 100);
    }

    /** La targa dell'AI vale se è una targa scritta nel testo; altrimenti la prima vicino a "targa". */
    private static function targaDaAi(?string $ai, string $testo): ?string
    {
        $ai = null === $ai ? '' : strtoupper((string) preg_replace('/\s+/', '', $ai));
        if (1 === preg_match('/^[A-Z]{2}\d{3}[A-Z]{2}$/', $ai) && TestoVoci::nelTesto($ai, $testo)) {
            return $ai;
        }

        return TestoVoci::targhe($testo)[0] ?? null;
    }

    /** La data dell'AI vale solo se compare nel testo. */
    private static function dataDaAi(string $data, string $testo): ?string
    {
        foreach (TestoVoci::date($data) as $d) {
            if (\in_array($d, TestoVoci::date($testo), true)) {
                return $d;
            }
        }

        return null;
    }

    /** @param list<MembroFamiglia> $persone */
    private function persona(Requisito $r, ?string $nome, string $testo, array $persone, ?MembroFamiglia $personaDocumento, string $nomeFile = ''): ?MembroFamiglia
    {
        if (!$r->perPersona) {
            return null;
        }
        if (null !== $personaDocumento && \in_array($personaDocumento, $persone, true)) {
            return $personaDocumento;
        }
        $trovate = [];
        foreach ($persone as $p) {
            $a = $p->getAnagrafica();
            $cf = $a->getCodiceFiscale();
            if ((null !== $cf && '' !== $cf && str_contains(mb_strtoupper($testo), mb_strtoupper($cf)))
                || (null !== $nome && TestoVoci::nelTesto($nome, $testo) && self::stessoNome($nome, $p->getDisplayName()))) {
                $trovate[] = $p;
            }
        }

        if (1 === \count($trovate)) {
            return $trovate[0];
        }
        // Nel nome del file ("ci_mario.pdf"): vale se il nome è di una sola persona della pratica.
        $nelNome = [];
        $paroleFile = explode(' ', Requisito::nomeNormale($nomeFile));
        foreach ($persone as $p) {
            $proprio = Requisito::normale((string) $p->getAnagrafica()->getNome());
            if (mb_strlen($proprio) >= 3 && \in_array($proprio, $paroleFile, true)) {
                $nelNome[] = $p;
            }
        }

        return 1 === \count($nelNome) ? $nelNome[0] : (1 === \count($persone) ? $persone[0] : null);
    }

    private static function stessoNome(string $a, string $b): bool
    {
        $parole = static fn (string $s): array => array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($s)) ?: [], static fn ($w) => mb_strlen($w) >= 3);
        $x = $parole($a);
        $y = $parole($b);

        return [] !== $x && \count(array_intersect($x, $y)) >= min(2, \count($x), \count($y));
    }
}
