<?php

namespace App\Pratiche;

use App\Pratiche\Modello\Campo;
use App\Pratiche\Modello\Requisito;
use App\Shared\Errore\DatiNonValidi;

/**
 * Controlla e normalizza i valori di una voce secondo i campi del requisito:
 * importo "123.45", data "AAAA-MM-GG", tipo tra quelli del requisito,
 * tracciabile si/no/non_risulta, testi brevi. Errori in italiano (422).
 */
final class ValoriVoce
{
    public const MAX_CENTESIMI = 1_000_000_000;

    /**
     * @param array<string, mixed> $dati    valori ricevuti
     * @param array<string, mixed> $attuali valori di partenza (in modifica)
     *
     * @return array<string, mixed>
     */
    public static function normalizza(Requisito $requisito, array $dati, array $attuali = []): array
    {
        $valori = array_intersect_key($attuali, $requisito->campi + ['nota' => true]);
        foreach ($requisito->campi as $codice => $campo) {
            if (!\array_key_exists($codice, $dati)) {
                continue;
            }
            $valori[$codice] = self::campo($requisito, $campo, $dati[$codice]);
        }
        if (\array_key_exists('nota', $dati)) {
            $valori['nota'] = self::testo($dati['nota'], 500, 'La nota può avere al massimo 500 caratteri.');
        }

        return array_filter($valori, static fn ($v) => null !== $v);
    }

    /** Campi obbligatori ancora vuoti (servono per confermare). @return list<string> etichette */
    public static function mancanti(Requisito $requisito, array $valori): array
    {
        $mancano = [];
        foreach ($requisito->campi as $codice => $campo) {
            if ($campo->obbligatorio && null === ($valori[$codice] ?? null)) {
                $mancano[] = $campo->etichetta;
            }
        }

        return $mancano;
    }

    private static function campo(Requisito $requisito, Campo $campo, mixed $valore): ?string
    {
        if (null === $valore || '' === $valore) {
            return null;
        }

        return match ($campo->tipo) {
            'importo' => null === ($c = self::centesimi($valore)) ? throw new DatiNonValidi(sprintf('%s: importo non valido, usa il formato 123.45.', $campo->etichetta)) : self::formatta($c),
            'data' => self::data($valore, $campo->etichetta),
            'scelta' => \is_string($valore) && null !== $requisito->tipo($valore) ? $valore : throw new DatiNonValidi(sprintf('%s: scegli uno dei valori proposti.', $campo->etichetta)),
            'tracciabile' => \is_string($valore) && \in_array($valore, Campo::TRACCIABILE, true) ? $valore : throw new DatiNonValidi(sprintf('%s: vale "si", "no" o "non_risulta".', $campo->etichetta)),
            'targa' => \is_string($valore) && 1 === preg_match('/^[A-Z]{2}\d{3}[A-Z]{2}$/', $t = strtoupper((string) preg_replace('/\s+/', '', $valore))) ? $t : throw new DatiNonValidi(sprintf('%s: targa non valida (es. AB123CD).', $campo->etichetta)),
            'iban' => \is_string($valore) && TestoVoci::ibanValido($valore) ? strtoupper((string) preg_replace('/\s+/', '', $valore)) : throw new DatiNonValidi(sprintf('%s: IBAN non valido.', $campo->etichetta)),
            default => self::testo($valore, 200, sprintf('%s: al massimo 200 caratteri.', $campo->etichetta)),
        };
    }

    /** "1.234,56", "1234,56", "1234.56", 12 → centesimi; null se non valido o non positivo. */
    public static function centesimi(mixed $valore): ?int
    {
        if (\is_int($valore) || \is_float($valore)) {
            $valore = (string) $valore;
        }
        if (!\is_string($valore)) {
            return null;
        }
        $t = trim(str_replace(['€', ' '], '', $valore));
        if (1 === preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $t)) {
            $t = str_replace(['.', ','], ['', '.'], $t);
        } else {
            $t = str_replace(',', '.', $t);
        }
        if (1 !== preg_match('/^\d{1,10}(\.\d{1,2})?$/', $t)) {
            return null;
        }
        $c = (int) round((float) $t * 100);

        return $c > 0 && $c <= self::MAX_CENTESIMI ? $c : null;
    }

    public static function formatta(int $centesimi): string
    {
        return sprintf('%d.%02d', intdiv($centesimi, 100), $centesimi % 100);
    }

    private static function data(mixed $valore, string $etichetta): string
    {
        $t = \is_string($valore) ? trim($valore) : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $t);
        if (false === $d || $d->format('Y-m-d') !== $t || (int) $d->format('Y') < 1990) {
            throw new DatiNonValidi(sprintf('%s: data non valida, usa il formato AAAA-MM-GG.', $etichetta));
        }

        return $t;
    }

    private static function testo(mixed $valore, int $max, string $errore): ?string
    {
        $t = \is_scalar($valore) ? trim((string) preg_replace('/\s+/u', ' ', (string) $valore)) : '';
        if (mb_strlen($t) > $max) {
            throw new DatiNonValidi($errore);
        }

        return '' === $t ? null : $t;
    }
}
