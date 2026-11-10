<?php

namespace App\Import\Enum;

/**
 * Servizi esterni da cui importare documenti. Ogni valore ha un'implementazione
 * di App\Import\Provider\ImportProviderInterface: per aggiungerne uno (es. OneDrive)
 * basta un nuovo caso qui e una nuova classe provider, senza toccare il resto.
 */
enum ImportProvider: string
{
    case GOOGLE_DRIVE = 'google_drive';

    public function label(): string
    {
        return match ($this) {
            self::GOOGLE_DRIVE => 'Google Drive',
        };
    }
}
