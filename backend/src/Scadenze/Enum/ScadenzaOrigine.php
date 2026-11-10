<?php

namespace App\Scadenze\Enum;

enum ScadenzaOrigine: string
{
    /** Creata da un utente della famiglia: la gestisce lui. */
    case MANUALE = 'manuale';
    /** Creata da una richiesta di un ente (fase "enti"): non si spunta a mano. */
    case RICHIESTA = 'richiesta';
}
