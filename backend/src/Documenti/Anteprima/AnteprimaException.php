<?php

namespace App\Documenti\Anteprima;

final class AnteprimaException extends \RuntimeException
{
    public static function nonConfigurato(): self
    {
        return new self('Convertitore dei documenti non configurato (GOTENBERG_URL): l\'anteprima non è disponibile, il file si può scaricare.');
    }
}
