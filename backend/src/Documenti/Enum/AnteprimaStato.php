<?php

namespace App\Documenti\Enum;

/**
 * Copia per la visualizzazione di un documento (scheda "Anteprima").
 * - NON_SERVE: PDF, JPEG e PNG si mostrano così come sono (l'anteprima è l'originale);
 * - IN_CODA: conversione in attesa del worker (Office → PDF, HEIC → JPEG);
 * - PRONTA: copia salvata (cifrata come l'originale);
 * - FALLITA: conversione non riuscita o convertitore non configurato (motivo in anteprimaErrore).
 */
enum AnteprimaStato: string
{
    case NON_SERVE = 'non_serve';
    case IN_CODA = 'in_coda';
    case PRONTA = 'pronta';
    case FALLITA = 'fallita';
}
