<?php

namespace App\Shared\Errore;

/**
 * Errore della logica applicativa, senza dipendere da HTTP: i servizi (usati
 * anche da job e comandi) lanciano queste; ErroreDominioListener le traduce
 * nella risposta HTTP corrispondente, con lo stesso messaggio.
 */
abstract class ErroreDominio extends \RuntimeException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
