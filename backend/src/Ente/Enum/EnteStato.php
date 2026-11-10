<?php

namespace App\Ente\Enum;

/**
 * Verifica dell'ente. Solo VERIFICATO può collegare famiglie e inviare richieste;
 * negli altri stati l'ente può preparare anni, gruppi, iscritti e operatori.
 */
enum EnteStato: string
{
    case EMAIL_DA_CONFERMARE = 'email_da_confermare';
    case IN_VERIFICA = 'in_verifica';
    case INTEGRAZIONI = 'integrazioni';
    case VERIFICATO = 'verificato';
    case RESPINTO = 'respinto';
}
