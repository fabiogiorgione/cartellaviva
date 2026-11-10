<?php

namespace App\Scadenze\Enum;

enum ScadenzaStato: string
{
    case APERTA = 'aperta';
    case FATTA = 'fatta';
    /** Solo per le scadenze nate da una richiesta di un ente: la famiglia ha già inviato. */
    case IN_VERIFICA = 'in_verifica';
}
