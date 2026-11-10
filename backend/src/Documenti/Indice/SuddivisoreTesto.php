<?php

namespace App\Documenti\Indice;

/**
 * Divide il testo OCR in parti di lunghezza limitata, rispettando quando si può
 * righe e frasi: un solo vettore per un documento di molte pagine perderebbe i
 * dettagli. Oltre $maxParti il resto del testo non viene indicizzato.
 */
final class SuddivisoreTesto
{
    /** @return list<string> */
    public static function parti(string $testo, int $maxCaratteri = 1500, int $maxParti = 8): array
    {
        $righe = preg_split('/\R+/u', $testo) ?: [];
        $parti = [];
        $corrente = '';
        foreach ($righe as $riga) {
            $riga = trim((string) preg_replace('/\s+/u', ' ', $riga));
            if ('' === $riga) {
                continue;
            }
            // Una riga troppo lunga si spezza (prima sulle frasi, poi a lunghezza fissa).
            foreach (self::spezza($riga, $maxCaratteri) as $pezzo) {
                if ('' !== $corrente && mb_strlen($corrente) + 1 + mb_strlen($pezzo) > $maxCaratteri) {
                    $parti[] = $corrente;
                    $corrente = '';
                    if (\count($parti) >= $maxParti) {
                        return $parti;
                    }
                }
                $corrente = '' === $corrente ? $pezzo : $corrente."\n".$pezzo;
            }
        }
        if ('' !== $corrente && \count($parti) < $maxParti) {
            $parti[] = $corrente;
        }

        return $parti;
    }

    /** @return list<string> */
    private static function spezza(string $riga, int $max): array
    {
        if (mb_strlen($riga) <= $max) {
            return [$riga];
        }
        $pezzi = [];
        $corrente = '';
        foreach (preg_split('/(?<=[.!?;])\s+/u', $riga) ?: [$riga] as $frase) {
            foreach (mb_str_split($frase, $max) as $f) {
                if ('' !== $corrente && mb_strlen($corrente) + 1 + mb_strlen($f) > $max) {
                    $pezzi[] = $corrente;
                    $corrente = '';
                }
                $corrente = '' === $corrente ? $f : $corrente.' '.$f;
            }
        }
        if ('' !== $corrente) {
            $pezzi[] = $corrente;
        }

        return $pezzi;
    }
}
