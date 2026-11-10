<?php

namespace App\Documenti\Ocr;

interface OcrEngineInterface
{
    /**
     * Estrae il testo dal contenuto (già decifrato, in memoria).
     *
     * @throws OcrException se l'estrazione fallisce
     */
    public function extract(string $contents, string $mimeType): string;
}
