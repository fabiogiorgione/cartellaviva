<?php

namespace App\Ente;

use App\Ente\Entity\ModelloRichiesta;
use App\Ente\Entity\Richiesta;
use App\Ente\Entity\RichiestaDestinatario;

/** Regole di una richiesta: modello d'origine, validità, rinnovo, conservazione, modalità di firma. */
final class RegoleRichiesta
{
    public function __construct(
        public readonly ?ModelloRichiesta $modello = null,
        public readonly bool $chiediValidita = false,
        public readonly ?int $rinnovoGiorni = null,
        public readonly ?int $conservazioneMesi = null,
        public readonly ?RichiestaDestinatario $rinnovoDi = null,
        public readonly string $modalitaFirma = Richiesta::FIRMA_CARTACEA,
    ) {
    }

    public static function daModello(ModelloRichiesta $m, ?RichiestaDestinatario $rinnovoDi = null): self
    {
        return new self($m, $m->isChiediValidita(), $m->getRinnovoGiorni(), $m->getConservazioneMesi(), $rinnovoDi, $m->getModalitaFirma());
    }
}
