<?php

namespace App\Pratiche\Enum;

enum VoceStato: string
{
    case PROPOSTA = 'proposta';
    case CONFERMATA = 'confermata';
    case ESCLUSA = 'esclusa';
}
