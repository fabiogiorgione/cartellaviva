<?php

namespace App\Pratiche\Enum;

enum PagamentoStato: string
{
    case DA_PAGARE = 'da_pagare';
    case PAGATO = 'pagato';
}
