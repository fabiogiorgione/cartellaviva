<?php

namespace App\Import\Enum;

/**
 * Come si assegna la categoria ai documenti importati.
 * - cartelle: dal nome della sottocartella di primo livello;
 * - ai_contenuto: in "Da categorizzare", poi classificati dal modello locale dopo l'OCR.
 */
enum ImportStrategia: string
{
    case CARTELLE = 'cartelle';
    case AI_CONTENUTO = 'ai_contenuto';
}
