<?php

namespace App\Ente;

use App\Ente\Enum\RichiestaTipo;

/** Campi di un modello di richiesta, già letti e controllati nella forma dalla richiesta HTTP. */
final class DatiModello
{
    public function __construct(
        public readonly string $nome,
        public readonly RichiestaTipo $tipo,
        public readonly string $titolo,
        public readonly ?string $istruzioni,
        public readonly string $firmatari,
        public readonly bool $chiediValidita,
        public readonly ?int $rinnovoGiorni,
        public readonly ?int $conservazioneMesi,
        public readonly int $giorniRisposta,
        public readonly ?int $ricorrenzaGiorno,
        public readonly ?int $ricorrenzaMese,
        public readonly string $modalitaFirma,
    ) {
    }
}
