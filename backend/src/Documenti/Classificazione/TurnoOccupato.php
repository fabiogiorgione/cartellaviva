<?php

namespace App\Documenti\Classificazione;

/** Nessun turno libero per una proposta di categoria: si riprova tra qualche secondo. */
final class TurnoOccupato extends \RuntimeException
{
}
