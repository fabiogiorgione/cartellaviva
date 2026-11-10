<?php

namespace App\Documenti\Indice;

/** Dati estratti dal testo di un documento, già ricontrollati sul testo (AiEstrattoreDati). */
final class DatiEstratti
{
    /**
     * @param list<string> $tag         parole chiave brevi, minuscole
     * @param list<string> $riferimenti codici normalizzati (maiuscole e cifre): targa, POD, polizza...
     */
    public function __construct(
        public readonly ?string $emittente = null,
        public readonly ?string $data = null,
        public readonly ?string $importo = null,
        public readonly ?string $numero = null,
        public readonly ?string $intestatario = null,
        public readonly array $tag = [],
        public readonly array $riferimenti = [],
    ) {
    }

    /**
     * La data trovata, se può essere la data del documento: un documento non
     * viene dal futuro (una data così è una scadenza letta per sbaglio).
     */
    public function dataDocumento(): ?\DateTimeImmutable
    {
        $data = null === $this->data ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $this->data);
        if (false === $data || $data->format('Y-m-d') !== $this->data || (int) $data->format('Y') < 1900 || $data > new \DateTimeImmutable('today')) {
            return null;
        }

        return $data;
    }

    /** @return array{emittente: ?string, data: ?string, importo: ?string, numero: ?string, intestatario: ?string} */
    public function dati(): array
    {
        return [
            'emittente' => $this->emittente,
            'data' => $this->data,
            'importo' => $this->importo,
            'numero' => $this->numero,
            'intestatario' => $this->intestatario,
        ];
    }
}
