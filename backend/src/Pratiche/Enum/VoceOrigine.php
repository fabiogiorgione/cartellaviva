<?php

namespace App\Pratiche\Enum;

enum VoceOrigine: string
{
    case AI_SERVER = 'ai_server';
    case AI_TELEFONO = 'ai_telefono';
    case MANUALE = 'manuale';
}
