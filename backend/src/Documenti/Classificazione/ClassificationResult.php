<?php

namespace App\Documenti\Classificazione;

final class ClassificationResult
{
    public function __construct(
        /** Nome di una delle categorie ammesse; null = "nessuna" o risposta non valida (bassa confidenza). */
        public readonly ?string $categoria,
        /** Risposta grezza del modello (troncata), solo per l'audit. */
        public readonly string $risposta,
        public readonly string $modello,
    ) {
    }
}
