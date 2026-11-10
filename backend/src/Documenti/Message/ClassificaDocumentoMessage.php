<?php

namespace App\Documenti\Message;

/** Classificazione automatica di un documento dopo l'OCR. */
final class ClassificaDocumentoMessage
{
    public function __construct(public readonly string $documentoId)
    {
    }
}
