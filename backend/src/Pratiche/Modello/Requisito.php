<?php

namespace App\Pratiche\Modello;

/** Cosa raccogliere per una pratica (es. "Spese sanitarie") e come riconoscerlo nei documenti. */
final class Requisito
{
    /** La data della voce deve essere nell'anno di riferimento. */
    public const DATA_RIFERIMENTO = 'riferimento';
    /** ...nell'anno dopo il riferimento (es. la CU, emessa l'anno dopo). */
    public const DATA_DOPO = 'dopo';
    /** ...in qualsiasi anno della finestra della pratica. */
    public const DATA_LIBERA = 'libera';

    /**
     * @param list<string>            $categorie slug delle categorie di sistema dei documenti candidati
     * @param list<string>            $parole    parole chiave (minuscole) cercate nel testo
     * @param array<string, TipoVoce> $tipi
     * @param array<string, Campo>    $campi
     */
    public function __construct(
        public readonly string $codice,
        public readonly string $etichetta,
        public readonly string $descrizione,
        public readonly bool $perPersona,
        public readonly bool $obbligatorio,
        public readonly bool $somma,
        public readonly array $categorie,
        public readonly array $parole,
        public readonly array $tipi,
        public readonly array $campi,
        public readonly string $data = self::DATA_RIFERIMENTO,
        /** 'iban' o 'targa': codici da cercare nei documenti candidati, ognuno una voce da completare */
        public readonly ?string $scopri = null,
    ) {
    }

    /** Anno in cui deve cadere la data della voce (null = qualsiasi). */
    public function annoAtteso(ModelloPratica $modello): ?int
    {
        return match ($this->data) {
            self::DATA_DOPO => $modello->annoRiferimento() + 1,
            self::DATA_LIBERA => null,
            default => $modello->annoRiferimento(),
        };
    }

    public function tipo(?string $codice): ?TipoVoce
    {
        return null === $codice ? null : ($this->tipi[$codice] ?? null);
    }

    /** Il testo (già minuscolo) contiene una delle parole chiave? */
    public function parlaDi(string $testoMinuscolo): bool
    {
        foreach ($this->parole as $parola) {
            if (self::contiene($testoMinuscolo, $parola)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La parola compare all'inizio di una parola del testo ("farmac" trova
     * "farmacia", "retta" non trova "corretta").
     */
    public static function contiene(string $testoMinuscolo, string $parola): bool
    {
        return str_contains($testoMinuscolo, $parola)
            && 1 === preg_match('~(?<![\p{L}\p{N}])'.preg_quote($parola, '~').'~u', $testoMinuscolo);
    }

    /** @return array<string, mixed> */
    public function vista(): array
    {
        return [
            'codice' => $this->codice,
            'etichetta' => $this->etichetta,
            'descrizione' => $this->descrizione,
            'per' => $this->perPersona ? 'persona' : 'famiglia',
            'obbligatorio' => $this->obbligatorio,
            'somma' => $this->somma,
            'scopri' => $this->scopri,
            'tipi' => array_values(array_map(static fn (TipoVoce $t) => $t->vista(), $this->tipi)),
            'campi' => array_values(array_map(static fn (Campo $c) => $c->vista(), $this->campi)),
        ];
    }
}
