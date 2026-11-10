<?php

namespace App\Documenti\Message;

/** Genera la copia per la scheda "Anteprima" di un documento (worker). */
final class AnteprimaDocumentoMessage
{
    public function __construct(public readonly string $documentoId)
    {
    }
}
