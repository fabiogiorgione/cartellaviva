<?php

namespace App\Documenti\Ocr;

use App\Documenti\TestoSemplice;
use Symfony\Component\Process\Process;

/**
 * OCR con Tesseract. Tutto passa da stdin/stdout dei processi: il contenuto
 * (eventualmente appena decifrato) non viene MAI scritto su disco.
 *
 * - immagini JPEG/PNG: tesseract diretto;
 * - HEIC/HEIF, GIF e WEBP: ImageMagick le converte in PNG (pipe; delle GIF e
 *   WEBP animate il primo fotogramma) e poi tesseract;
 * - testo semplice (TXT): letto così com'è, convertito in UTF-8 se serve;
 * - PDF: prima il testo già presente (pdftotext); se è una scansione senza
 *   testo, ogni pagina viene rasterizzata con pdftocairo (pipe) e passata a tesseract.
 */
final class TesseractOcrEngine implements OcrEngineInterface
{
    private const LANGUAGES = 'ita+eng';
    private const MAX_PDF_PAGES = 20;
    private const MIN_TEXT_LAYER_CHARS = 25;
    private const TIMEOUT = 120;

    public function extract(string $contents, string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/png' => $this->tesseract($contents),
            'image/heic', 'image/heif' => $this->tesseract($this->converti('heic:-', $contents, 'conversione HEIC')),
            'image/gif' => $this->tesseract($this->converti('gif:-[0]', $contents, 'conversione GIF')),
            'image/webp' => $this->tesseract($this->converti('webp:-[0]', $contents, 'conversione WEBP')),
            'text/plain' => trim(str_replace(["\r\n", "\r"], "\n", TestoSemplice::inUtf8($contents))),
            'application/pdf' => $this->pdf($contents),
            default => throw new OcrException(sprintf('OCR non supportato per %s', $mimeType)),
        };
    }

    private function pdf(string $pdf): string
    {
        $textLayer = trim(str_replace("\f", "\n", $this->run(['pdftotext', '-layout', '-', '-'], $pdf, 'lettura testo PDF')));
        if (mb_strlen(preg_replace('/\s+/', '', $textLayer) ?? '') >= self::MIN_TEXT_LAYER_CHARS) {
            return $textLayer;
        }

        $info = $this->run(['pdfinfo', '-'], $pdf, 'lettura PDF');
        $pages = preg_match('/^Pages:\s+(\d+)/m', $info, $m) ? (int) $m[1] : 1;
        $texts = [];
        for ($page = 1; $page <= min($pages, self::MAX_PDF_PAGES); ++$page) {
            $png = $this->run(['pdftocairo', '-png', '-r', '200', '-f', (string) $page, '-l', (string) $page, '-singlefile', '-', '-'], $pdf, "rasterizzazione pagina $page");
            $texts[] = $this->tesseract($png);
        }

        return trim(implode("\n\n", $texts));
    }

    /**
     * Converte in PNG per tesseract, in grigio a 8 bit: all'OCR basta, e una foto
     * HEIC da 12 MP (10 bit) a colori diventerebbe un PNG a 16 bit da 30+ MB, che
     * con le copie in memoria supera il memory_limit del worker.
     */
    private function converti(string $sorgente, string $contents, string $step): string
    {
        return $this->run(['convert', $sorgente, '-colorspace', 'Gray', '-depth', '8', 'png:-'], $contents, $step);
    }

    private function tesseract(string $image): string
    {
        return trim($this->run(['tesseract', 'stdin', 'stdout', '-l', self::LANGUAGES], $image, 'tesseract'));
    }

    /** @param list<string> $command */
    private function run(array $command, string $input, string $step): string
    {
        $process = new Process($command, null, ['OMP_THREAD_LIMIT' => '1'], $input, self::TIMEOUT);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new OcrException(sprintf('%s non riuscita (exit %d): %s', ucfirst($step), (int) $process->getExitCode(), mb_substr(trim($process->getErrorOutput()), 0, 300)));
        }

        return $process->getOutput();
    }
}
