<?php

namespace App\Documenti\Indice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sono scaricati i modelli dell'analisi dei documenti? Un modello mancante non
 * dà errore nell'analisi (la parte si salta): senza questo controllo i
 * documenti resterebbero "in attesa" senza spiegazione.
 *
 * Per ogni modello: disattivato (variabile vuota), pronto, mancante (non
 * scaricato in Ollama) o non_raggiungibile (Ollama spento o lento).
 */
class StatoModelliAi
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiEstrattoreDati $estrattore,
        private readonly EmbedderInterface $embedder,
        #[Autowire(env: 'OLLAMA_URL')]
        private readonly string $url,
    ) {
    }

    /** @return array{dati: string, ricerca: string} */
    public function stato(): array
    {
        $richiesti = array_filter([
            'dati' => $this->estrattore->isEnabled() ? $this->estrattore->modello() : null,
            'ricerca' => $this->embedder->isEnabled() ? $this->embedder->modello() : null,
        ]);
        $scaricati = [] === $richiesti ? [] : $this->scaricati();

        $stato = static fn (string $chiave): string => match (true) {
            !isset($richiesti[$chiave]) => 'disattivato',
            null === $scaricati => 'non_raggiungibile',
            // "bge-m3" in .env è "bge-m3:latest" in Ollama.
            \in_array($richiesti[$chiave], $scaricati, true), \in_array($richiesti[$chiave].':latest', $scaricati, true) => 'pronto',
            default => 'mancante',
        };

        return ['dati' => $stato('dati'), 'ricerca' => $stato('ricerca')];
    }

    /** @return list<string>|null null = Ollama non risponde */
    private function scaricati(): ?array
    {
        try {
            $tags = $this->httpClient->request('GET', rtrim($this->url, '/').'/api/tags', ['timeout' => 2, 'max_duration' => 3])->toArray();
        } catch (\Throwable) {
            return null;
        }

        return array_values(array_filter(array_column($tags['models'] ?? [], 'name'), 'is_string'));
    }
}
