<?php

namespace App\Documenti\Message;

/**
 * Richiesta di OCR per un documento. Contiene SOLO l'id: nessun contenuto né
 * dato personale transita nella coda (tabella messenger_messages).
 */
final class OcrDocumentoMessage
{
    public function __construct(public readonly string $documentoId)
    {
    }
}
