<?php

namespace App\ConnettoreAi;

/**
 * Una catena di classificazione è arrivata a una domanda che il Mac non ha
 * ancora fatto: la domanda è il prossimo passo del lavoro. Non è un errore e
 * non estende RuntimeException, che per il wizard vuol dire "modello spento".
 */
final class InAttesaDelMac extends \Exception
{
    /** @param array{istruzioni: string, prompt: string, schema: string} $richiesta */
    public function __construct(public readonly array $richiesta, public readonly string $chiave)
    {
        parent::__construct('Domanda in attesa del Mac.');
    }
}
