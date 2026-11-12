<?php

namespace App\Pratiche\Modello;

/** Un campo che si legge da un documento per una voce (importo, data, ...). */
final class Campo
{
    public const TIPI = ['importo', 'data', 'testo', 'scelta', 'tracciabile', 'iban', 'targa'];
    public const TRACCIABILE = ['si', 'no', 'non_risulta'];

    public function __construct(
        public readonly string $codice,
        public readonly string $tipo,
        public readonly string $etichetta,
        public readonly bool $obbligatorio,
        /** @var list<string> per gli importi: etichette (minuscole) vicino a cui cercarlo nel testo senza AI */
        public readonly array $etichette = [],
        /** testo libero (descrizione): non deve comparire nel testo del documento */
        public readonly bool $libero = false,
        /** campo con cui confrontarlo (es. il saldo della DSU precompilata con il saldo letto) */
        public readonly ?string $confronta = null,
        /** compilato solo dall'utente, mai dall'AI (es. i valori della precompilata) */
        public readonly bool $soloUtente = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function vista(): array
    {
        return ['codice' => $this->codice, 'tipo' => $this->tipo, 'etichetta' => $this->etichetta, 'obbligatorio' => $this->obbligatorio, 'confronta' => $this->confronta, 'soloUtente' => $this->soloUtente];
    }
}
