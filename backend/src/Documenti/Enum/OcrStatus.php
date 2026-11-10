<?php

namespace App\Documenti\Enum;

enum OcrStatus: string
{
    case IN_CODA = 'in_coda';
    case COMPLETATO = 'completato';
    case FALLITO = 'fallito';
    case NON_APPLICABILE = 'non_applicabile';
}
