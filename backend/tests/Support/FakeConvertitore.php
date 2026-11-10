<?php

namespace App\Tests\Support;

use App\Documenti\Anteprima\AnteprimaException;
use App\Documenti\Anteprima\ConvertitorePdfInterface;
use Dompdf\Dompdf;

/**
 * Convertitore dei test: nessun Gotenberg. Produce un PDF vero (dompdf) con il
 * testo "Anteprima di <nome>", così l'OCR successivo ha qualcosa da leggere.
 * Con $configurato = false si simula GOTENBERG_URL vuoto.
 */
final class FakeConvertitore implements ConvertitorePdfInterface
{
    public bool $configurato = true;
    public int $chiamate = 0;

    public function isConfigurato(): bool
    {
        return $this->configurato;
    }

    public function inPdf(string $contenuto, string $nomeFile): string
    {
        if (!$this->configurato) {
            throw AnteprimaException::nonConfigurato();
        }
        ++$this->chiamate;
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<html><body><p>Anteprima di '.htmlspecialchars($nomeFile).' ('.\strlen($contenuto).' byte)</p></body></html>');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
