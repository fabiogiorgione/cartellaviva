<?php

namespace App\Pratiche;

use App\Pratiche\Modello\Requisito;

/**
 * Regole deterministiche sul testo di un documento per le voci delle pratiche:
 * importo totale, date, pagamento tracciabile, e i controlli che un valore
 * proposto dall'AI compaia davvero nel testo.
 */
final class TestoVoci
{
    private const IMPORTO = '(\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2})(?!\d)';
    private const DATA = '(?<!\d)(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})(?!\d)';

    /** Importo totale (in centesimi) vicino a un'etichetta di totale, o il primo con €. */
    public static function importoTotale(string $testo): ?int
    {
        $basso = mb_strtolower($testo);
        foreach ([
            '~(?:totale\s+(?:documento|complessivo|pagato|fattura|da\s+pagare|euro)?|importo\s+(?:pagato|totale|complessivo)|tot\.|pagato|netto\s+a\s+pagare)[^\d\n]{0,25}'.self::IMPORTO.'~u',
            '~(?:€|euro|eur)\s*'.self::IMPORTO.'~u',
            '~(?<![\d,.])'.self::IMPORTO.'\s*(?:€|euro|eur)~u',
        ] as $modello) {
            if (preg_match_all($modello, $basso, $m)) {
                foreach ($m[1] as $v) {
                    if (null !== ($c = ValoriVoce::centesimi($v))) {
                        return $c;
                    }
                }
            }
        }

        return null;
    }

    /** L'importo compare nel testo (in formato italiano o con il punto)? */
    public static function importoNelTesto(int $centesimi, string $testo): bool
    {
        $intero = intdiv($centesimi, 100);
        $dec = sprintf('%02d', $centesimi % 100);
        $forme = [$intero.','.$dec, number_format($intero, 0, '', '.').','.$dec, $intero.'.'.$dec];
        foreach (array_unique($forme) as $f) {
            if (1 === preg_match('~(?<![\d.,])'.preg_quote($f, '~').'(?!\d)~', $testo)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> date trovate nel testo, AAAA-MM-GG, nell'ordine */
    public static function date(string $testo): array
    {
        preg_match_all('~'.self::DATA.'~', $testo, $m, \PREG_SET_ORDER);
        $date = [];
        foreach ($m as [, $g, $mm, $a]) {
            $anno = 2 === \strlen($a) ? 2000 + (int) $a : (int) $a;
            if (checkdate((int) $mm, (int) $g, $anno) && $anno >= 1990 && $anno <= 2100) {
                $date[] = sprintf('%04d-%02d-%02d', $anno, (int) $mm, (int) $g);
            }
        }

        return array_values(array_unique($date));
    }

    /** Prima data del testo nell'anno indicato. */
    public static function dataNellAnno(string $testo, int $anno): ?string
    {
        foreach (self::date($testo) as $d) {
            if ((int) substr($d, 0, 4) === $anno) {
                return $d;
            }
        }

        return null;
    }

    /** "si" se il testo dice come è stato pagato con mezzi tracciabili, "no" se in contanti, altrimenti null. */
    public static function tracciabile(string $testo): ?string
    {
        $basso = mb_strtolower($testo);
        if (1 === preg_match('~\b(?:pagamento\s+elettronico|pagamento\s+con\s+carta|carta\s+di\s+credito|carta\s+di\s+debito|bancomat|pagobancomat|pos\b|bonifico|assegno|satispay|apple\s+pay|google\s+pay|visa|mastercard|maestro)~u', $basso)) {
            return 'si';
        }
        if (1 === preg_match('~\b(?:contanti|contante|pagamento\s+in\s+contanti)\b~u', $basso)) {
            return 'no';
        }

        return null;
    }

    /** Importo vicino a una delle etichette (es. "giacenza media"), in centesimi: il primo di importiVicini(). */
    public static function importoDopo(string $testo, array $etichette, bool $ultimo = false): ?int
    {
        $trovati = self::importiVicini($testo, $etichette);

        return [] === $trovati ? null : ($ultimo ? $trovati[\count($trovati) - 1] : $trovati[0]);
    }

    /**
     * Importi vicini alla prima etichetta (in ordine di elenco) che ne ha:
     * subito dopo ("Saldo contabile al 31/12/2024 euro 3.210,55"), subito prima
     * ("1.250,00 SALDO FINALE", estratti conto a colonne) o nella stessa colonna
     * delle righe sotto (tabelle con l'intestazione "GIACENZA MEDIA"). In centesimi,
     * nell'ordine del testo.
     *
     * @param list<string> $etichette minuscole
     *
     * @return list<int>
     */
    public static function importiVicini(string $testo, array $etichette): array
    {
        $basso = mb_strtolower($testo);
        $righe = explode("\n", $basso);
        // Per etichetta e importo sulla stessa riga contano le parole, non l'impaginazione:
        // gli spazi di allineamento diventano uno. Le colonne (sotto) usano le righe originali.
        $compatto = (string) preg_replace('/[ \t]{2,}/', ' ', $basso);
        foreach ($etichette as $e) {
            $trovati = [];
            $sullaRiga = [];
            if (preg_match_all('~'.preg_quote($e, '~').'[^\d\n]{0,60}(?:(?:\d{1,2}[\/.\-]\d{1,2}[\/.\-])?\d{4}(?![\d,])[^\d\n]{0,20})?'.self::IMPORTO.'~u', $compatto, $m, \PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as [$v, $pos]) {
                    $sullaRiga[$pos] = $v;
                }
            }
            if (preg_match_all('~'.self::IMPORTO.'[^\d\n]{0,12}'.preg_quote($e, '~').'~u', $compatto, $m, \PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as [$v, $pos]) {
                    $sullaRiga[$pos] = $v;
                }
            }
            ksort($sullaRiga);
            // Tabelle: l'etichetta nell'intestazione, l'importo nella stessa colonna di una delle 4 righe sotto.
            $offset = 0;
            foreach ($righe as $n => $riga) {
                $col = mb_strpos($riga, $e);
                // Solo da una riga di intestazione, senza importi: altrimenti è testo, non una tabella.
                if (false !== $col && !preg_match('~'.self::IMPORTO.'~u', $riga)) {
                    for ($k = $n + 1; $k <= min($n + 4, \count($righe) - 1); ++$k) {
                        if (!preg_match_all('~'.self::IMPORTO.'~u', $righe[$k], $mm, \PREG_OFFSET_CAPTURE)) {
                            continue;
                        }
                        foreach ($mm[1] as [$v, $byte]) {
                            $da = mb_strlen(substr($righe[$k], 0, $byte));
                            $a = $da + mb_strlen($v);
                            if ($a >= $col - 8 && $da <= $col + mb_strlen($e) + 12) {
                                $trovati[$offset + $byte] = $v;
                                break 2;
                            }
                        }
                    }
                }
                $offset += \strlen($riga) + 1;
            }
            ksort($trovati);
            // Prima quelli sulla stessa riga dell'etichetta, poi quelli in colonna.
            $centesimi = array_values(array_filter(array_map(static fn (string $v) => ValoriVoce::centesimi($v), [...array_values($sullaRiga), ...array_values($trovati)]), static fn ($c) => null !== $c));
            if ([] !== $centesimi) {
                return $centesimi;
            }
        }

        return [];
    }

    /** IBAN italiani validi (controllo mod 97) nel testo, anche scritti a gruppi. @return list<string> */
    public static function iban(string $testo): array
    {
        preg_match_all('~\bIT\s?\d{2}\s?[A-Z](?:\s?\d){10}(?:\s?[0-9A-Z]){12}\b~i', $testo, $m);
        $out = [];
        foreach ($m[0] as $grezzo) {
            $iban = strtoupper((string) preg_replace('/\s+/', '', $grezzo));
            if (self::ibanValido($iban)) {
                $out[] = $iban;
            }
        }

        return array_values(array_unique($out));
    }

    public static function ibanValido(string $iban): bool
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (1 !== preg_match('/^[A-Z]{2}\d{2}[0-9A-Z]{11,30}$/', $iban)) {
            return false;
        }
        $numero = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $c) {
            $numero .= ctype_alpha($c) ? (string) (\ord($c) - 55) : $c;
        }
        $resto = 0;
        foreach (str_split($numero, 7) as $pezzo) {
            $resto = (int) (((string) $resto).$pezzo) % 97;
        }

        return 1 === $resto;
    }

    /** Targhe italiane (AA000AA) vicino alla parola "targa". @return list<string> */
    public static function targhe(string $testo): array
    {
        preg_match_all('~targa[^\n]{0,20}?\b([A-Z]{2}\s?\d{3}\s?[A-Z]{2})\b~iu', $testo, $m);

        return array_values(array_unique(array_map(static fn ($t) => strtoupper((string) preg_replace('/\s+/', '', $t)), $m[1])));
    }

    /**
     * Le parti del testo utili per i requisiti, entro [max] caratteri: tutto se
     * ci sta, altrimenti l'inizio (intestazione) e le finestre intorno alle
     * parole chiave, nell'ordine del testo.
     *
     * @param list<string> $parole minuscole
     */
    public static function porzioni(string $testo, array $parole, int $max): string
    {
        if (mb_strlen($testo) <= $max) {
            return $testo;
        }
        $basso = Requisito::piatto($testo);
        $tratti = [[0, min(600, $max)]];
        foreach ($parole as $p) {
            $p = Requisito::piatto($p);
            for ($i = mb_strpos($basso, $p); false !== $i; $i = mb_strpos($basso, $p, $i + 1)) {
                $tratti[] = [max(0, $i - 300), $i + 500];
            }
        }
        usort($tratti, static fn ($a, $b) => $a[0] <=> $b[0]);
        $uniti = [];
        foreach ($tratti as [$da, $a]) {
            if ([] !== $uniti && $da <= $uniti[\count($uniti) - 1][1]) {
                $uniti[\count($uniti) - 1][1] = max($uniti[\count($uniti) - 1][1], $a);
            } else {
                $uniti[] = [$da, $a];
            }
        }
        $out = '';
        foreach ($uniti as [$da, $a]) {
            $pezzo = mb_substr($testo, $da, $a - $da);
            if (mb_strlen($out) + mb_strlen($pezzo) + 5 > $max) {
                $pezzo = mb_substr($pezzo, 0, max(0, $max - mb_strlen($out) - 5));
            }
            $out .= ('' === $out ? '' : "\n[…]\n").$pezzo;
            if (mb_strlen($out) >= $max - 5) {
                break;
            }
        }

        return $out;
    }

    /** Il testo breve (emittente, persona) compare nel testo? Confronto su lettere e cifre minuscole. */
    public static function nelTesto(?string $breve, string $testo): bool
    {
        if (null === $breve) {
            return false;
        }
        $compatto = static fn (string $s): string => (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($s));
        $chiave = $compatto($breve);

        return mb_strlen($chiave) >= 3 && str_contains($compatto($testo), $chiave);
    }
}
