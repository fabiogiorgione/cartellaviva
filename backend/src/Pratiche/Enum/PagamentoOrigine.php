<?php

namespace App\Pratiche\Enum;

enum PagamentoOrigine: string
{
    /** Nato da un documento (proposta dell'AI confermata dalla persona). */
    case DOCUMENTO = 'documento';
    case MANUALE = 'manuale';
}
