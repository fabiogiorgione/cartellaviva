<?php

namespace App\Scadenze\Enum;

enum ScadenzaRicorrenza: string
{
    case NESSUNA = 'nessuna';
    case MENSILE = 'mensile';
    case ANNUALE = 'annuale';

    /**
     * Data della ricorrenza successiva. A fine mese si resta sull'ultimo giorno
     * valido: 31 gennaio -> 28/29 febbraio (mai 3 marzo), 29 febbraio -> 28 febbraio.
     */
    public function next(\DateTimeImmutable $data): ?\DateTimeImmutable
    {
        $months = match ($this) {
            self::NESSUNA => null,
            self::MENSILE => 1,
            self::ANNUALE => 12,
        };
        if (null === $months) {
            return null;
        }
        $year = (int) $data->format('Y');
        $month = (int) $data->format('n') + $months;
        $year += intdiv($month - 1, 12);
        $month = ($month - 1) % 12 + 1;
        $firstOfMonth = $data->setDate($year, $month, 1);
        $day = min((int) $data->format('j'), (int) $firstOfMonth->format('t'));

        return $firstOfMonth->setDate($year, $month, $day);
    }
}
