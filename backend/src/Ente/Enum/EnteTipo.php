<?php

namespace App\Ente\Enum;

/** Per ora si registrano solo scuole e associazioni. */
enum EnteTipo: string
{
    case SCUOLA = 'scuola';
    case ASSOCIAZIONE = 'associazione';

    public function label(): string
    {
        return match ($this) {
            self::SCUOLA => 'Scuola',
            self::ASSOCIAZIONE => 'Associazione',
        };
    }
}
