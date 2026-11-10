<?php

namespace App\Documenti\Message;

/** Indicizzazione di un documento non cifrato: dati estratti, tag, riferimenti, vettori. */
final class IndicizzaDocumentoMessage
{
    public function __construct(public readonly string $documentoId)
    {
    }
}
