<?php

namespace App\Documenti\Indice;

/**
 * Ollama risponde ma il modello non c'è (HTTP 404: "ollama pull" non eseguito).
 * Non ha senso ritentare: quella parte dell'indice si salta, e si recupera poi
 * con app:documenti:indicizza dopo aver scaricato il modello.
 */
final class ModelloNonDisponibileException extends \RuntimeException
{
}
