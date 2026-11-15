<?php

namespace App\ConnettoreAi;

/** Errore del token endpoint nel formato OAuth (RFC 6749 §5.2, RFC 8628 §3.5): 400 con "error". */
final class ErroreOAuth extends \RuntimeException
{
    public function __construct(public readonly string $codice, string $descrizione)
    {
        parent::__construct($descrizione);
    }
}
