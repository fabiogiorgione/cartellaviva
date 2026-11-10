<?php

namespace App\Documenti\Anteprima;

use App\Documenti\UploadPolicy;
use Symfony\Component\Process\Process;

/**
 * Ricava la copia per la scheda "Anteprima" da un file originale, tutto in
 * memoria: Office → PDF (convertitore isolato), HEIC/HEIF → JPEG (ImageMagick,
 * via pipe come per l'OCR). Non tocca entità né storage.
 */
final class GeneratoreAnteprima
{
    /** Lato lungo massimo della JPEG ricavata da una HEIC: basta per leggere, pesa poco. */
    private const LATO_MAX = 2400;

    public function __construct(private readonly ConvertitorePdfInterface $convertitore)
    {
    }

    public function isConvertitoreConfigurato(): bool
    {
        return $this->convertitore->isConfigurato();
    }

    /**
     * @return array{0: string, 1: string} contenuto e tipo della copia
     *
     * @throws AnteprimaException
     */
    public function genera(string $contenuto, string $mime, string $nomeFile): array
    {
        return match (UploadPolicy::anteprimaPer($mime)) {
            'application/pdf' => [$this->convertitore->inPdf($contenuto, $nomeFile), 'application/pdf'],
            'image/jpeg' => [$this->jpeg($contenuto), 'image/jpeg'],
            default => throw new AnteprimaException(sprintf('Per %s l\'anteprima è il file stesso.', $mime)),
        };
    }

    private function jpeg(string $heic): string
    {
        $process = new Process(['convert', 'heic:-', '-auto-orient', '-resize', self::LATO_MAX.'x'.self::LATO_MAX.'>', '-quality', '85', 'jpg:-'], null, ['OMP_THREAD_LIMIT' => '1'], $heic, 60);
        $process->run();
        if (!$process->isSuccessful() || '' === $process->getOutput()) {
            throw new AnteprimaException('Conversione della foto HEIC non riuscita: '.mb_substr(trim($process->getErrorOutput()), 0, 200));
        }

        return $process->getOutput();
    }
}
