<?php

namespace App\Import\Message;

/** Sincronizzazione di una sorgente di import (solo l'id: il resto si ricarica dal DB). */
final class ImportJobMessage
{
    public function __construct(public readonly string $importSourceId)
    {
    }
}
