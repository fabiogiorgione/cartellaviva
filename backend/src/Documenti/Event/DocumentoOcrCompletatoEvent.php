<?php

namespace App\Documenti\Event;

use App\Documenti\Entity\Documento;

/**
 * Emesso dal worker OCR dopo che il testo è stato salvato (ocr_status = completato).
 * È il punto di aggancio per le elaborazioni successive all'OCR (classificazione
 * automatica, in futuro scadenze...): i listener NON devono fare lavoro pesante
 * qui, ma mettere in coda un proprio messaggio.
 */
final class DocumentoOcrCompletatoEvent
{
    public function __construct(public readonly Documento $documento)
    {
    }
}
