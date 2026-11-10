<?php

namespace App\Import\Enum;

enum ImportSourceStato: string
{
    /** Creata, account esterno non ancora collegato. */
    case NON_CONFIGURATO = 'non_configurato';
    case ATTIVO = 'attivo';
    case DISATTIVATO = 'disattivato';
    /** L'ultima sincronizzazione è fallita (motivo in ultimoErrore); si può riprovare. */
    case ERRORE = 'errore';
}
