<?php

namespace App\Documenti\Indice;

/** Trasforma testi in vettori (embedding) per la ricerca per significato. */
interface EmbedderInterface
{
    public function isEnabled(): bool;

    /** Nome del modello: i vettori di modelli diversi non si confrontano mai. */
    public function modello(): string;

    /**
     * @param list<string> $testi
     *
     * @return list<list<float>> un vettore per testo, nello stesso ordine
     */
    public function embed(array $testi, float $timeout = 300): array;
}
