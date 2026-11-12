<?php

namespace App\Pratiche;

use App\Pratiche\Entity\Pagamento;

/**
 * Riconosce nel testo OCR un avviso di pagamento (PagoPA, bollettino) e ne
 * legge i dati con regole deterministiche, senza AI.
 *
 * Un avviso si propone solo se il testo contiene una parola tipica (pagoPA,
 * "avviso di pagamento", CBILL, bollettino…) E un codice avviso di 18 cifre
 * (anche a gruppi: "3012 0000 4567 8912 34") che inizia con 0-3 e sta vicino a
 * "codice avviso" / "numero avviso" / "IUV". Una bolletta senza codice avviso
 * non genera proposte. L'importo vale solo se compare nel testo; la scadenza
 * solo se è futura o recente.
 */
final class AvvisoPagamentoEstrattore
{
    private const PAROLE = ['pagopa', 'codice avviso', 'avviso di pagamento', 'cbill', 'bollettino'];
    private const ETICHETTE_CODICE = '(?:codice\s+avviso|numero\s+avviso|n\.?\s*avviso|iuv)';

    private const MESI = [
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6,
        'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
    ];

    /** Importo in formato italiano: 1.234,56 oppure 180,40. */
    private const IMPORTO = '(\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2})(?!\d)';

    /** Fino a quanti giorni fa vale ancora una scadenza (un avviso scaduto da poco si può ancora pagare). */
    private const GIORNI_RECENTE = 30;

    /**
     * @return array{codiceAvviso: string, importoCentesimi: ?int, scadenza: ?\DateTimeImmutable, codiceFiscaleEnte: ?string, ente: ?string}|null
     */
    public function estrai(string $testo, ?\DateTimeImmutable $oggi = null): ?array
    {
        $oggi ??= new \DateTimeImmutable('today');
        $testo = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $testo);
        $basso = mb_strtolower($testo);

        $gate = false;
        foreach (self::PAROLE as $parola) {
            if (str_contains($basso, $parola)) {
                $gate = true;
                break;
            }
        }
        if (!$gate) {
            return null;
        }
        $codice = $this->codiceAvviso($testo);
        if (null === $codice) {
            return null;
        }

        return [
            'codiceAvviso' => $codice,
            'importoCentesimi' => $this->importo($basso),
            'scadenza' => $this->scadenza($basso, $oggi),
            'codiceFiscaleEnte' => $this->codiceFiscaleEnte($basso),
            'ente' => $this->ente($testo),
        ];
    }

    private function codiceAvviso(string $testo): ?string
    {
        // Sequenze di cifre separate da singoli spazi: un codice è un gruppo di
        // parti consecutive che fanno esattamente 18 cifre (17 o 19 non vanno bene).
        preg_match_all('~(?<!\d)\d+(?:[ ]\d+)*(?!\d)~', $testo, $m, \PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$sequenza, $offset]) {
            $parti = explode(' ', $sequenza);
            $posizione = $offset;
            foreach (array_keys($parti) as $i) {
                $somma = 0;
                $codice = '';
                for ($j = $i; $j < \count($parti) && $somma < 18; ++$j) {
                    $somma += \strlen($parti[$j]);
                    $codice .= $parti[$j];
                }
                if (18 === $somma && $codice[0] <= '3' && $this->haEtichettaCodice($testo, $posizione)) {
                    return $codice;
                }
                $posizione += \strlen($parti[$i]) + 1;
            }
        }

        return null;
    }

    /** L'etichetta sta poco prima del codice (stessa riga o riga precedente) o subito dopo. */
    private function haEtichettaCodice(string $testo, int $posizione): bool
    {
        // Etichette solo ASCII: si cerca sui byte, senza modificatore "u" (il taglio può spezzare un carattere).
        $prima = substr($testo, max(0, $posizione - 70), min(70, $posizione));
        if (1 === preg_match('~'.self::ETICHETTE_CODICE.'~i', $prima)) {
            return true;
        }

        return 1 === preg_match('~^[^\n]{0,40}'.self::ETICHETTE_CODICE.'~i', substr($testo, $posizione + 18, 60));
    }

    private function importo(string $basso): ?int
    {
        $modelli = [
            // Etichetta di importo seguita dal valore (anche con €/euro in mezzo).
            '~(?:importo(?:\s+(?:da\s+pagare|dovuto|totale))?|totale\s+da\s+pagare|da\s+pagare|totale\s+dovuto|totale)[^\d\n]{0,25}'.self::IMPORTO.'~u',
            '~(?:€|euro|eur)\s*'.self::IMPORTO.'~u',
            '~(?<![\d,.])'.self::IMPORTO.'\s*(?:€|euro|eur)~u',
        ];
        foreach ($modelli as $modello) {
            if (preg_match_all($modello, $basso, $m)) {
                foreach ($m[1] as $valore) {
                    $centesimi = (int) round((float) str_replace(',', '.', str_replace('.', '', $valore)) * 100);
                    if ($centesimi > 0 && $centesimi <= Pagamento::MAX_CENTESIMI) {
                        return $centesimi;
                    }
                }
            }
        }

        return null;
    }

    private function scadenza(string $basso, \DateTimeImmutable $oggi): ?\DateTimeImmutable
    {
        $mesi = implode('|', array_keys(self::MESI));
        $data = '(?:(\d{1,2})[./\-](\d{1,2})[./\-](\d{4})|(\d{1,2})\s+('.$mesi.')\s+(\d{4}))';
        preg_match_all('~(?:da\s+pagare\s+entro(?:\s+il)?|entro\s+il|entro|scadenza|scade\s+il|scade)[^\d\n]{0,25}'.$data.'~u', $basso, $m, \PREG_SET_ORDER);
        $limiteBasso = $oggi->modify('-'.self::GIORNI_RECENTE.' days');
        $limiteAlto = $oggi->modify('+5 years');
        foreach ($m as $x) {
            [$g, $mese, $anno] = '' !== ($x[1] ?? '')
                ? [(int) $x[1], (int) $x[2], (int) $x[3]]
                : [(int) $x[4], self::MESI[$x[5]], (int) $x[6]];
            if (!checkdate($mese, $g, $anno)) {
                continue;
            }
            $d = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $anno, $mese, $g));
            if ($d >= $limiteBasso && $d <= $limiteAlto) {
                return $d;
            }
        }

        return null;
    }

    private function codiceFiscaleEnte(string $basso): ?string
    {
        if (preg_match('~(?:ente\s+creditore|codice\s+fiscale\s+ente|cf\s+ente|c\.f\.\s+ente|cod\.?\s*fisc\w*(?:\s+ente)?|c\.?\s*f\.?)[^\d]{0,70}(\d{11})(?!\d)~u', $basso, $m)) {
            return $m[1];
        }

        return null;
    }

    private function ente(string $testo): ?string
    {
        // "Ente creditore: Comune di Milano" oppure nome sulla riga dopo l'etichetta.
        if (preg_match('~ente\s+creditore\s*[:\-]?[ \t]*([^\n]*)\n?([^\n]*)~iu', $testo, $m)) {
            foreach ([$m[1], $m[2]] as $riga) {
                $pulita = self::pulisciEnte($riga);
                if (null !== $pulita) {
                    return $pulita;
                }
            }
        }
        // Intestazione: nelle prime righe, un ente pubblico noto.
        $righe = array_slice(array_values(array_filter(array_map('trim', explode("\n", $testo)), static fn (string $r) => '' !== $r)), 0, 8);
        foreach ($righe as $riga) {
            if (1 === preg_match('~^(comune|regione|provincia|citt[àa]\s+metropolitana|agenzia|azienda|asl|ats|inps|unione\s+dei\s+comuni|unione\s+montana|camera\s+di\s+commercio|universit[àa])\b~iu', $riga)) {
                return self::pulisciEnte($riga);
            }
        }

        return null;
    }

    /** Nome dell'ente senza codici fiscali o etichette in coda; null se non sembra un nome. */
    private static function pulisciEnte(string $riga): ?string
    {
        $riga = (string) preg_replace('~\s*(?:c\.?\s*f\.?|cod\.?\s*fisc\w*|codice\s+fiscale|p\.?\s*iva|partita\s+iva|\d{8,}).*$~iu', '', $riga);
        $riga = trim((string) preg_replace('~\s+~u', ' ', $riga), " \t:;,-");
        if (mb_strlen($riga) < 3 || 1 !== preg_match('~\p{L}{3}~u', $riga)) {
            return null;
        }

        return mb_substr($riga, 0, 200);
    }
}
