<?php

namespace App\Scadenze\Message;

/** Dopo l'OCR: cerca nel testo una data di scadenza da proporre. */
final class ProponiScadenzaMessage
{
    public function __construct(public readonly string $documentoId)
    {
    }
}
