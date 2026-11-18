<?php

namespace App\Documenti\Message;

/**
 * Indicizzazione di un documento non cifrato: dati estratti, tag, riferimenti, vettori.
 * forzaServer: i dati li legge la VM anche con il Mac online. soloDati: i vettori ci
 * sono già (il lavoro d'indice torna dal Mac al server), si rifanno solo i dati.
 */
final class IndicizzaDocumentoMessage
{
    public function __construct(
        public readonly string $documentoId,
        public readonly bool $forzaServer = false,
        public readonly bool $soloDati = false,
    ) {
    }
}
