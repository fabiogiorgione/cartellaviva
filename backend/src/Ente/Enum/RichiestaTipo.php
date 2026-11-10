<?php

namespace App\Ente\Enum;

enum RichiestaTipo: string
{
    /** L'ente allega un PDF: la famiglia lo stampa, firma e rimanda la scansione. */
    case FIRMA = 'firma';
    /** La famiglia invia un documento (scansione, file o dal suo archivio). */
    case INVIO = 'invio';
    /** La famiglia conferma di aver letto (circolari, regolamenti). */
    case PRESA_VISIONE = 'presa_visione';

    public function richiedeVerifica(): bool
    {
        return self::PRESA_VISIONE !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::FIRMA => 'Firma',
            self::INVIO => 'Invio documento',
            self::PRESA_VISIONE => 'Presa visione',
        };
    }
}
