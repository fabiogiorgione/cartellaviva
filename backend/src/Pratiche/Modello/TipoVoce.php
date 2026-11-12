<?php

namespace App\Pratiche\Modello;

/**
 * Tipo di una voce (es. "Farmaci" tra le spese sanitarie) con la regola sul
 * pagamento tracciabile da mostrare come controllo, mai come calcolo.
 */
final class TipoVoce
{
    public const RICHIESTO = 'richiesto';
    public const NON_RICHIESTO = 'non_richiesto';
    public const DIPENDE = 'dipende';

    public function __construct(
        public readonly string $codice,
        public readonly string $etichetta,
        public readonly string $tracciabile,
        public readonly ?string $nota,
        /** @var list<string> parole (minuscole) che indicano il tipo nel testo, senza AI */
        public readonly array $parole = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function vista(): array
    {
        return ['codice' => $this->codice, 'etichetta' => $this->etichetta, 'tracciabile' => $this->tracciabile, 'nota' => $this->nota];
    }
}
