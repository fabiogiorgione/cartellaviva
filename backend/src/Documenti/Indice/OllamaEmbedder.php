<?php

namespace App\Documenti\Indice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Embedding con il modello locale di Ollama (servizio "ollama", non esposto).
 * Il modello si sceglie SOLO con OLLAMA_EMBED_MODEL (consigliato bge-m3,
 * multilingue); vuoto = ricerca per significato disattivata.
 * Il testo dei documenti non esce mai dalla macchina.
 */
final class OllamaEmbedder implements EmbedderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'OLLAMA_URL')]
        private readonly string $url,
        #[Autowire(env: 'OLLAMA_EMBED_MODEL')]
        private readonly string $model,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->model) && '' !== trim($this->url);
    }

    public function modello(): string
    {
        return $this->model;
    }

    public function embed(array $testi, float $timeout = 300): array
    {
        if ([] === $testi) {
            return [];
        }
        $response = $this->httpClient->request('POST', rtrim($this->url, '/').'/api/embed', ['timeout' => $timeout, 'max_duration' => 2 * $timeout, 'json' => [
            'model' => $this->model,
            'input' => $testi,
            // Un testo oltre il contesto del modello viene troncato invece di dare errore.
            'truncate' => true,
        ]]);
        $status = $response->getStatusCode();
        if (404 === $status) {
            throw new ModelloNonDisponibileException(sprintf('Modello di embedding "%s" non scaricato: ollama pull %s', $this->model, $this->model));
        }
        if (200 !== $status) {
            // Es. 404 = modello non scaricato: "ollama pull <OLLAMA_EMBED_MODEL>" (vedi README).
            throw new \RuntimeException(sprintf('Ollama (embedding) ha risposto HTTP %d: %s', $status, mb_substr($response->getContent(false), 0, 200)));
        }
        $vettori = $response->toArray()['embeddings'] ?? null;
        if (!\is_array($vettori) || \count($vettori) !== \count($testi)) {
            throw new \RuntimeException('Ollama (embedding): risposta senza un vettore per ogni testo.');
        }

        return array_map(static function (mixed $v): array {
            if (!\is_array($v) || [] === $v) {
                throw new \RuntimeException('Ollama (embedding): vettore non valido.');
            }

            return array_map(static fn (mixed $x) => \is_int($x) || \is_float($x) ? (float) $x : throw new \RuntimeException('Ollama (embedding): valore non numerico.'), array_values($v));
        }, array_values($vettori));
    }
}
