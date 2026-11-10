<?php

namespace App\Shared\Enum;

enum AuditOutcome: string
{
    /** Decisione di autorizzazione positiva. */
    case GRANTED = 'granted';

    /** Decisione di autorizzazione negata (o regola di dominio violata). */
    case DENIED = 'denied';

    /** Operazione completata. */
    case SUCCESS = 'success';

    /** Operazione tentata ma fallita (es. credenziali errate). */
    case FAILURE = 'failure';
}
