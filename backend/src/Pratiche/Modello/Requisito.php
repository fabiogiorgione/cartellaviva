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
     * @param list<string>            $parole    parole chiave (già passate da normale()) cercate nel testo e nel nome del file
     * @param list<string>            $nomi      parole cercate solo nel nome del file, intere ("ci", "cf")
     * @param list<string>            $senza     parole che escludono il riconoscimento (es. "polizza" per la disabilità)
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
        public readonly array $nomi = [],
        public readonly array $senza = [],
        /** Vale anche un documento più vecchio della finestra della pratica (carta d'identità, libretto, visura). */
        public readonly bool $ancheVecchi = false,
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

    /**
     * Testo pronto per cercare le parole chiave: minuscolo, senza accenti, con
     * apostrofi dritti e spazi singoli. Così "CERTIFICAZIONE\nUNICA", "d’imposta"
     * e "identita'" si trovano come "certificazione unica", "d'imposta", "identità".
     */
    public static function normale(string $testo): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::piatto($testo)));
    }

    /** Minuscolo, senza accenti e con apostrofi dritti, carattere per carattere (le posizioni non cambiano). */
    public static function piatto(string $testo): string
    {
        return strtr(mb_strtolower($testo), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u',
            '’' => "'", '‘' => "'", '`' => "'", '´' => "'", '′' => "'",
        ]);
    }

    /** Il nome del file come testo: "ci_fabio-rossi.pdf" diventa "ci fabio rossi". */
    public static function nomeNormale(string $nomeFile): string
    {
        $senzaEstensione = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $nomeFile);

        return self::normale((string) preg_replace('/[^\p{L}\p{N}\']+/u', ' ', $senzaEstensione));
    }

    /**
     * Il documento parla di questo requisito? Le parole chiave si cercano nel
     * testo e nel nome del file (entrambi già passati da normale() e
     * nomeNormale()); i "nomi" solo nel nome del file, come parole intere.
     */
    public function parlaDi(string $testoNormale, string $nomeNormale = ''): bool
    {
        if ($this->escluso($testoNormale, $nomeNormale)) {
            return false;
        }
        $tutto = '' === $nomeNormale ? $testoNormale : $nomeNormale."\n".$testoNormale;
        foreach ($this->parole as $parola) {
            if (self::contiene($tutto, $parola)) {
                return true;
            }
        }
        foreach ($this->nomi as $nome) {
            if ('' !== $nomeNormale && 1 === preg_match('~(?<![\p{L}\p{N}])'.preg_quote($nome, '~').'(?![\p{L}\p{N}])~u', $nomeNormale)) {
                return true;
            }
        }

        return false;
    }

    /** Il documento contiene una delle parole "senza" (non è di questo requisito)? */
    public function escluso(string $testoNormale, string $nomeNormale = ''): bool
    {
        $tutto = '' === $nomeNormale ? $testoNormale : $nomeNormale."\n".$testoNormale;
        foreach ($this->senza as $parola) {
            if (self::contiene($tutto, $parola)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La parola compare all'inizio di una parola del testo ("farmac" trova
     * "farmacia", "retta" non trova "corretta").
     */
    public static function contiene(string $testoNormale, string $parola): bool
    {
        return str_contains($testoNormale, $parola)
            && 1 === preg_match('~(?<![\p{L}\p{N}])'.preg_quote($parola, '~').'~u', $testoNormale);
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
