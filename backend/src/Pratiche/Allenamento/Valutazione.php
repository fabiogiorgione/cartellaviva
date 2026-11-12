<?php

namespace App\Pratiche\Allenamento;

use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\ValoriVoce;

/**
 * Confronta le voci proposte dall'analisi con quelle giuste (confermate) e
 * accumula i conteggi: per requisito giuste, in più, mancate; per campo
 * quante volte il valore coincide (importi in centesimi, testi senza
 * maiuscole e punteggiatura).
 */
final class Valutazione
{
    /** @var array<string, array{oro: int, proposte: int, giuste: int, inPiu: int, mancate: int}> */
    public array $requisiti = [];

    /** @var array<string, array{confronti: int, uguali: int}> "requisito.campo" */
    public array $campi = [];

    public int $documenti = 0;

    /** @var array<string, int> requisito => documenti in cui non era nemmeno tra i candidati */
    public array $nonCandidati = [];

    /**
     * @param list<array{requisito: string, valori: array<string, string>}> $oro
     * @param list<array{requisito: string, valori: array<string, string>}> $previste
     * @param list<string>                                                 $nonCandidati
     */
    public function aggiungi(ModelloPratica $modello, array $oro, array $previste, array $nonCandidati = []): void
    {
        ++$this->documenti;
        foreach ($nonCandidati as $r) {
            $this->nonCandidati[$r] = ($this->nonCandidati[$r] ?? 0) + 1;
        }
        $codici = array_unique([...array_column($oro, 'requisito'), ...array_column($previste, 'requisito')]);
        foreach ($codici as $codice) {
            $g = array_values(array_filter($oro, static fn ($x) => $x['requisito'] === $codice));
            $p = array_values(array_filter($previste, static fn ($x) => $x['requisito'] === $codice));
            $riga = $this->requisiti[$codice] ??= ['oro' => 0, 'proposte' => 0, 'giuste' => 0, 'inPiu' => 0, 'mancate' => 0];
            $riga['oro'] += \count($g);
            $riga['proposte'] += \count($p);
            foreach (self::abbina($g, $p) as [$gi, $pi]) {
                ++$riga['giuste'];
                foreach ($g[$gi]['valori'] as $campo => $valore) {
                    $k = $codice.'.'.$campo;
                    $this->campi[$k] ??= ['confronti' => 0, 'uguali' => 0];
                    ++$this->campi[$k]['confronti'];
                    if (self::uguale($campo, $valore, $p[$pi]['valori'][$campo] ?? null)) {
                        ++$this->campi[$k]['uguali'];
                    }
                }
            }
            $riga['inPiu'] = $riga['proposte'] - $riga['giuste'];
            $riga['mancate'] = $riga['oro'] - $riga['giuste'];
            $this->requisiti[$codice] = $riga;
        }
    }

    /**
     * Abbina voci giuste e proposte dello stesso requisito: prima per IBAN o
     * targa, poi per importo, poi nell'ordine.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function abbina(array $g, array $p): array
    {
        $coppie = [];
        $liberi = array_keys($p);
        foreach (['iban', 'targa', 'importo', null] as $chiave) {
            foreach ($g as $gi => $x) {
                if (\in_array($gi, array_column($coppie, 0), true)) {
                    continue;
                }
                foreach ($liberi as $j => $pi) {
                    if (null === $chiave || (isset($x['valori'][$chiave]) && self::uguale($chiave, $x['valori'][$chiave], $p[$pi]['valori'][$chiave] ?? null))) {
                        $coppie[] = [$gi, $pi];
                        unset($liberi[$j]);
                        break;
                    }
                }
            }
        }

        return $coppie;
    }

    public static function uguale(string $campo, ?string $a, ?string $b): bool
    {
        if (null === $a || null === $b) {
            return $a === $b;
        }
        $ca = ValoriVoce::centesimi($a);
        $cb = ValoriVoce::centesimi($b);
        if (null !== $ca && null !== $cb && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $a)) {
            return $ca === $cb;
        }
        $compatto = static fn (string $s): string => (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($s));

        return $compatto($a) === $compatto($b);
    }

    /** Totali: precisione e richiamo su tutte le voci. @return array{precisione: ?float, richiamo: ?float} */
    public function totali(): array
    {
        $giuste = array_sum(array_column($this->requisiti, 'giuste'));
        $proposte = array_sum(array_column($this->requisiti, 'proposte'));
        $oro = array_sum(array_column($this->requisiti, 'oro'));

        return ['precisione' => $proposte > 0 ? $giuste / $proposte : null, 'richiamo' => $oro > 0 ? $giuste / $oro : null];
    }
}
