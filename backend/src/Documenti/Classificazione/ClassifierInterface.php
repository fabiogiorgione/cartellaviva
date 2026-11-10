<?php

namespace App\Documenti\Classificazione;

/**
 * Classificatore del contenuto dei documenti. L'implementazione attuale è un
 * modello locale via Ollama (AiCategoryClassifier): il testo dei documenti non
 * esce mai dall'infrastruttura.
 */
interface ClassifierInterface
{
    /** Configurato (modello e servizio indicati nell'ambiente)? Se no, i documenti restano da categorizzare. */
    public function isEnabled(): bool;

    /**
     * Sceglie UNA categoria tra quelle indicate, oppure nessuna.
     *
     * @param list<string>                $categorie nomi delle categorie ammesse
     * @param array<string, list<string>> $esempi    nome della categoria => titoli di documenti che contiene già
     * @param list<string>                $indizi    righe di contesto (titolo, emittente, tag...)
     *
     * @throws \RuntimeException se il servizio non risponde (il messaggio verrà ritentato)
     */
    public function classify(string $nomeFile, string $testo, array $categorie, array $esempi = [], array $indizi = []): ClassificationResult;

    /**
     * Che cosa raccoglie di solito una categoria con questo nome (una frase), dagli
     * esempi e dal nome. Senza vedere nessun documento: serve a verifica().
     *
     * @param list<string> $esempi titoli di documenti che la categoria contiene già
     *
     * @throws \RuntimeException se il servizio non risponde
     */
    public function descriviCategoria(string $categoria, array $esempi): string;

    /**
     * Controllo sì/no della scelta di classify(): il documento rientra nella
     * descrizione della categoria? (Nel dubbio no.)
     *
     * @param list<string> $indizi
     *
     * @throws \RuntimeException se il servizio non risponde
     */
    public function verifica(string $nomeFile, string $testo, string $categoria, string $descrizione, array $indizi): bool;

    /**
     * Nome (validato) di una categoria nuova per un documento che non sta in nessuna
     * di quelle esistenti; null se il testo non basta o la risposta non è valida.
     *
     * @param list<string> $indizi
     * @param list<string> $esistenti categorie già scartate
     *
     * @throws \RuntimeException se il servizio non risponde
     */
    public function proponiNuova(string $nomeFile, string $testo, array $indizi, array $esistenti): ?NuovaCategoria;
}
