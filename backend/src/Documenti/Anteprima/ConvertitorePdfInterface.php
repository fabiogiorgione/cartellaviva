<?php

namespace App\Documenti\Anteprima;

/**
 * Converte un documento Office (DOCX, XLSX, ODT, ...) in PDF per la sola
 * visualizzazione. Il contenuto arriva e torna in memoria.
 */
interface ConvertitorePdfInterface
{
    public function isConfigurato(): bool;

    /** @throws AnteprimaException se non configurato o se la conversione non riesce */
    public function inPdf(string $contenuto, string $nomeFile): string;
}
