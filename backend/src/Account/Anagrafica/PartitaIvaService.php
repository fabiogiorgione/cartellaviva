<?php

namespace App\Account\Anagrafica;

/**
 * Validazione della partita IVA italiana (cifra di controllo).
 */
final class PartitaIvaService
{
    public const ERROR_EMPTY = 2;
    public const ERROR_LENGTH = 3;
    public const ERROR_CHARACTERS = 4;
    public const ERROR_CHECKSUM = 5;

    public const MESSAGES = [
        self::ERROR_EMPTY => 'La Partita Iva non può essere vuota',
        self::ERROR_LENGTH => 'La Partita Iva deve essere di 11 caratteri',
        self::ERROR_CHARACTERS => 'La Partita Iva deve contenere solamente numeri',
        self::ERROR_CHECKSUM => 'La Partita Iva non rispetta i parametri di verifica',
    ];

    public static function normalize(?string $pi): string
    {
        return (string) preg_replace('/\s+/', '', (string) $pi);
    }

    /** null se valida, altrimenti uno dei codici ERROR_*. */
    public static function validate(?string $pi): ?int
    {
        $pi = self::normalize($pi);
        if ('' === $pi) {
            return self::ERROR_EMPTY;
        }
        if (11 !== \strlen($pi)) {
            return self::ERROR_LENGTH;
        }
        if (1 !== preg_match('/^[0-9]{11}$/D', $pi)) {
            return self::ERROR_CHARACTERS;
        }
        $s = 0;
        for ($i = 0; $i < 11; ++$i) {
            $n = \ord($pi[$i]) - \ord('0');
            if (1 === ($i & 1)) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $s += $n;
        }

        return 0 !== $s % 10 ? self::ERROR_CHECKSUM : null;
    }
}
