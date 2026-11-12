<?php

namespace App\Pratiche\Enum;

enum PraticaStato: string
{
    case IN_CORSO = 'in_corso';
    case PRONTA = 'pronta';
    case INVIATA = 'inviata';
    case ARCHIVIATA = 'archiviata';
}
