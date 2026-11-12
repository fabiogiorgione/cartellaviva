<?php

namespace App\Pratiche\Message;

/** Analizza un documento per una pratica (in coda, un documento alla volta). */
final class AnalizzaDocumentoPraticaMessage
{
    public function __construct(
        public readonly string $praticaId,
        public readonly string $documentoId,
    ) {
    }
}
