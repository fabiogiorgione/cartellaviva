<?php

namespace App\Scadenze;

/**
 * Trova nel testo OCR le date che possono essere una scadenza: future (fino a
 * 20 anni), con un punteggio che sale se vicino a parole come "scadenza",
 * "valido fino", "expiry". Deterministico: senza AI decide da solo; con l'AI
 * le stesse date sono l'unico elenco tra cui il modello può scegliere.
 */
final class ScadenzaEstrattore
{
    private const PAROLE = [
        'scadenza' => 3, 'scade' => 3, 'scad' => 2, 'valid' => 2, 'fino al' => 2, 'entro il' => 1,
        'expiry' => 3, 'expires' => 3, 'expiration' => 3, 'date of expiry' => 3, 'until' => 1,
        'rinnovo' => 1, 'revisione' => 2, 'data di fine' => 2, 'termine' => 1,
    ];

    /** Parole che indicano date NON di scadenza (nascita, rilascio, emissione). */
    private const CONTRARIE = ['nascita', 'nato', 'nata', 'birth', 'rilascio', 'rilasciat', 'emission', 'issue', 'emesso', 'emessa'];

    private const MESI = [
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6,
        'luglio' => 7, 'agosto' => 8, 'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
        'gen' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mag' => 5, 'giu' => 6, 'lug' => 7, 'ago' => 8, 'set' => 9, 'ott' => 10, 'nov' => 11, 'dic' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'dec' => 12,
    ];

    /**
     * @return list<array{data: \DateTimeImmutable, punteggio: int}> date future distinte, la più probabile prima
     */
    public function candidati(string $testo, ?\DateTimeImmutable $oggi = null): array
    {
        $oggi ??= new \DateTimeImmutable('today');
        $limite = $oggi->modify('+20 years');
        $basso = mb_strtolower($testo);
        $trovate = [];

        $mesi = implode('|', array_map('preg_quote', array_keys(self::MESI)));
        $pattern = '~(?<!\d)(?:(\d{1,2})[./\-](\d{1,2})[./\-](\d{4})|(\d{4})-(\d{2})-(\d{2})|(\d{1,2})\s+('.$mesi.')\.?\s+(\d{4}))(?!\d)~u';
        preg_match_all($pattern, $basso, $m, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($m as $match) {
            [$g, $mese, $anno] = match (true) {
                '' !== ($match[1][0] ?? '') => [(int) $match[1][0], (int) $match[2][0], (int) $match[3][0]],
                '' !== ($match[4][0] ?? '') => [(int) $match[6][0], (int) $match[5][0], (int) $match[4][0]],
                default => [(int) $match[7][0], self::MESI[$match[8][0]] ?? 0, (int) $match[9][0]],
            };
            if (!checkdate($mese, $g, $anno)) {
                continue;
            }
            $data = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $anno, $mese, $g));
            if ($data <= $oggi || $data > $limite) {
                continue;
            }
            // L'offset di preg è in byte: il contesto si prende in byte, poi si confronta come testo.
            $offset = $match[0][1];
            $prima = substr($basso, max(0, $offset - 60), min(60, $offset));
            // Decide l'etichetta più vicina alla data: "nascita 12/03/1980 scadenza 12/03/2036"
            // dà ragione a "scadenza" per la seconda data.
            [$posPositiva, $peso] = [-1, 0];
            foreach (self::PAROLE as $parola => $p) {
                $pos = strrpos($prima, $parola);
                if (false !== $pos && ($pos > $posPositiva || ($pos === $posPositiva && $p > $peso))) {
                    [$posPositiva, $peso] = [$pos, $p];
                }
            }
            $posContraria = -1;
            foreach (self::CONTRARIE as $parola) {
                $pos = strrpos($prima, $parola);
                $posContraria = false !== $pos ? max($posContraria, $pos) : $posContraria;
            }
            $punteggio = $posContraria > $posPositiva ? -3 : $peso;
            $chiave = $data->format('Y-m-d');
            if (!isset($trovate[$chiave]) || $trovate[$chiave]['punteggio'] < $punteggio) {
                $trovate[$chiave] = ['data' => $data, 'punteggio' => $punteggio];
            }
        }

        $lista = array_values($trovate);
        // Punteggio più alto; a parità, la data più lontana (nei documenti la scadenza segue il rilascio).
        usort($lista, static fn ($a, $b) => [$b['punteggio'], $b['data']] <=> [$a['punteggio'], $a['data']]);

        return $lista;
    }

    /** Senza AI: solo una data vicina a una parola di scadenza. */
    public function migliore(string $testo, ?\DateTimeImmutable $oggi = null): ?\DateTimeImmutable
    {
        $c = $this->candidati($testo, $oggi)[0] ?? null;

        return null !== $c && $c['punteggio'] > 0 ? $c['data'] : null;
    }
}
