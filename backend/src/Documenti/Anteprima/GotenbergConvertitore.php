<?php

namespace App\Documenti\Anteprima;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Conversione con Gotenberg (LibreOffice) nel container "convertitore":
 * rete interna senza internet, file temporanei in RAM. Si usa solo la route
 * LibreOffice; il documento passa nel corpo della richiesta, mai su disco qui.
 * GOTENBERG_URL vuoto = convertitore non configurato (anteprima "fallita" con
 * il motivo, il file resta scaricabile).
 */
final class GotenbergConvertitore implements ConvertitorePdfInterface
{
    private const TIMEOUT = 90;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::GOTENBERG_URL)%')]
        private readonly ?string $url = null,
    ) {
    }

    public function isConfigurato(): bool
    {
        return null !== $this->url && '' !== trim($this->url);
    }

    public function inPdf(string $contenuto, string $nomeFile): string
    {
        if (!$this->isConfigurato()) {
            throw AnteprimaException::nonConfigurato();
        }
        // LibreOffice riconosce il formato anche dall'estensione: nome neutro, estensione vera.
        $estensione = strtolower(pathinfo($nomeFile, \PATHINFO_EXTENSION)) ?: 'docx';
        $form = new FormDataPart(['files' => new DataPart($contenuto, 'documento.'.preg_replace('/[^a-z0-9]/', '', $estensione))]);

        try {
            $risposta = $this->httpClient->request('POST', rtrim((string) $this->url, '/').'/forms/libreoffice/convert', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
            ]);
            $stato = $risposta->getStatusCode();
            $pdf = $risposta->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new AnteprimaException('Convertitore dei documenti non raggiungibile: '.$e->getMessage(), 0, $e);
        }
        if (200 !== $stato || !str_starts_with($pdf, '%PDF')) {
            throw new AnteprimaException(sprintf('Conversione in PDF non riuscita (HTTP %d): il file potrebbe essere danneggiato o protetto da password.', $stato));
        }

        return $pdf;
    }
}
