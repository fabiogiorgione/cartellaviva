<?php

namespace App\Import\Exception;

/**
 * Errore che interrompe un'operazione di import (token revocato, cartella non
 * accessibile...). Il messaggio è pensato per l'utente: finisce in
 * ImportSource::ultimoErrore, quindi non deve contenere token né dati dei file.
 */
class ImportException extends \RuntimeException
{
}
