<?php

namespace App\Pratiche\Message;

/** Analizza un documento per una pratica (in coda, un documento alla volta). */
final class AnalizzaDocumentoPraticaMessage
{
    public function __construct(
        public readonly string $praticaId,
        public readonly string $documentoId,
        /** Analizza sul server anche se la famiglia ha un connettore AI online (ritorno dal Mac). */
        public readonly bool $forzaServer = false,
    ) {
    }
}
