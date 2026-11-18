<?php

namespace App\Documenti\Message;

/**
 * Classificazione automatica di un documento dopo l'OCR. forzaServer: la fa
 * l'Ollama del server anche con il Mac della famiglia online (il Mac non ha risposto).
 */
final class ClassificaDocumentoMessage
{
    public function __construct(
        public readonly string $documentoId,
        public readonly bool $forzaServer = false,
    ) {
    }
}
