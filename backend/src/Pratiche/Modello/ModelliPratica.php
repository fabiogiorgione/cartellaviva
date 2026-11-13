<?php

namespace App\Pratiche\Modello;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * I modelli di pratica disponibili: un file per modello e anno in
 * config/pratiche/<modello>-<anno>.yaml. Senza file per quell'anno il modello
 * non si può avviare. Un file malformato è un errore di chi lo ha scritto
 * (\LogicException), coperto dai test.
 */
class ModelliPratica
{
    /** Nomi dei modelli che il codice sa gestire. */
    public const NOMI = ['730' => '730', 'isee' => 'ISEE'];

    /** @var array<string, ModelloPratica>|null chiave "<modello>-<anno>" */
    private ?array $modelli = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/pratiche')]
        private readonly string $cartella,
    ) {
    }

    /** @return list<ModelloPratica> per modello e anno decrescente */
    public function tutti(): array
    {
        $tutti = array_values($this->carica());
        usort($tutti, static fn (ModelloPratica $a, ModelloPratica $b) => [$a->codice, -$a->anno] <=> [$b->codice, -$b->anno]);

        return $tutti;
    }

    public function trova(string $codice, int $anno): ?ModelloPratica
    {
        return $this->carica()[$codice.'-'.$anno] ?? null;
    }

    /**
     * L'anno da proporre: per il 730 le spese dell'anno prima fino a settembre
     * (la dichiarazione si presenta entro il 30 settembre), da ottobre quelle
     * dell'anno in corso, che si stanno raccogliendo.
     */
    public function annoProposto(string $codice, ?\DateTimeImmutable $oggi = null): ?int
    {
        $oggi ??= new \DateTimeImmutable('today');
        $anno = (int) $oggi->format('Y');
        // 730: fino a settembre le spese dell'anno prima, poi quelle dell'anno in corso.
        // ISEE: la DSU dell'anno in corso; da ottobre si prepara quella dell'anno dopo.
        $autunno = (int) $oggi->format('n') >= 10;
        $preferito = match ($codice) {
            '730' => $autunno ? $anno : $anno - 1,
            default => $autunno ? $anno + 1 : $anno,
        };
        foreach ([$preferito, $preferito - 1, $preferito + 1] as $a) {
            if (null !== $this->trova($codice, $a)) {
                return $a;
            }
        }

        return null;
    }

    /** @return array<string, ModelloPratica> */
    private function carica(): array
    {
        if (null !== $this->modelli) {
            return $this->modelli;
        }
        $this->modelli = [];
        foreach (glob($this->cartella.'/*.yaml') ?: [] as $file) {
            $modello = self::leggi(Yaml::parseFile($file), basename($file));
            $this->modelli[$modello->codice.'-'.$modello->anno] = $modello;
        }

        return $this->modelli;
    }

    /** @return list<string> parole chiave pronte per il confronto (vedi Requisito::normale) */
    private static function parole(mixed $elenco): array
    {
        return array_values(array_filter(array_map(static fn ($p) => Requisito::normale((string) $p), (array) $elenco), static fn (string $p) => '' !== $p));
    }

    public static function leggi(mixed $dati, string $file): ModelloPratica
    {
        $errore = static fn (string $cosa): \LogicException => new \LogicException(sprintf('Modello di pratica %s: %s.', $file, $cosa));
        if (!\is_array($dati)) {
            throw $errore('il file non contiene un oggetto');
        }
        $codice = (string) ($dati['modello'] ?? '');
        $anno = $dati['anno'] ?? null;
        if (!isset(self::NOMI[$codice]) || !\is_int($anno) || $anno < 2000 || $anno > 2100) {
            throw $errore('"modello" o "anno" non validi');
        }
        if ($file !== $codice.'-'.$anno.'.yaml') {
            throw $errore('il nome del file deve essere <modello>-<anno>.yaml');
        }
        $verificato = $dati['verificato'] ?? null;
        if (null !== $verificato && (!\is_string($verificato) || false === \DateTimeImmutable::createFromFormat('!Y-m-d', $verificato))) {
            throw $errore('"verificato" deve essere una data AAAA-MM-GG o ~');
        }
        $fonti = [];
        foreach ((array) ($dati['fonti'] ?? []) as $f) {
            if (!\is_array($f) || !\is_string($f['titolo'] ?? null) || !\is_string($f['url'] ?? null) || !str_starts_with($f['url'], 'https://')) {
                throw $errore('ogni fonte ha "titolo" e "url" https');
            }
            $fonti[] = ['titolo' => $f['titolo'], 'url' => $f['url']];
        }
        if ([] === $fonti) {
            throw $errore('serve almeno una fonte ufficiale');
        }

        $requisiti = [];
        foreach ((array) ($dati['requisiti'] ?? []) as $rc => $r) {
            if (!\is_array($r) || !\is_string($r['etichetta'] ?? null) || 1 !== preg_match('/^[a-z][a-z0-9_]{1,40}$/', (string) $rc)) {
                throw $errore("requisito \"$rc\" senza etichetta o con un codice non valido");
            }
            $per = $r['per'] ?? 'persona';
            if (!\in_array($per, ['persona', 'famiglia'], true)) {
                throw $errore("requisito \"$rc\": \"per\" vale persona o famiglia");
            }
            $tipi = [];
            foreach ((array) ($r['tipi'] ?? []) as $tc => $t) {
                $tracciabile = $t['tracciabile'] ?? TipoVoce::NON_RICHIESTO;
                if (!\is_array($t) || !\is_string($t['etichetta'] ?? null) || !\in_array($tracciabile, [TipoVoce::RICHIESTO, TipoVoce::NON_RICHIESTO, TipoVoce::DIPENDE], true)) {
                    throw $errore("tipo \"$rc.$tc\" non valido");
                }
                $tipi[(string) $tc] = new TipoVoce((string) $tc, $t['etichetta'], $tracciabile, \is_string($t['nota'] ?? null) ? $t['nota'] : null, self::parole($t['parole'] ?? []), self::parole($t['nomi'] ?? []));
            }
            $campi = [];
            foreach ((array) ($r['campi'] ?? []) as $cc => $c) {
                if (!\is_array($c) || !\in_array($c['tipo'] ?? null, Campo::TIPI, true) || !\is_string($c['etichetta'] ?? null)) {
                    throw $errore("campo \"$rc.$cc\" non valido");
                }
                $campi[(string) $cc] = new Campo(
                    (string) $cc,
                    $c['tipo'],
                    $c['etichetta'],
                    true === ($c['obbligatorio'] ?? false),
                    array_values(array_map(static fn ($p) => mb_strtolower((string) $p), (array) ($c['etichette'] ?? []))),
                    true === ($c['libero'] ?? false),
                    \is_string($c['confronta'] ?? null) ? $c['confronta'] : null,
                    true === ($c['soloUtente'] ?? false),
                    true === ($c['ultimo'] ?? false),
                );
            }
            if ([] === $campi) {
                throw $errore("requisito \"$rc\" senza campi");
            }
            foreach ($campi as $c) {
                if (null !== $c->confronta && !isset($campi[$c->confronta])) {
                    throw $errore("campo \"$rc.{$c->codice}\": confronta un campo che non c'è");
                }
            }
            $data = $r['data'] ?? Requisito::DATA_RIFERIMENTO;
            $scopri = $r['scopri'] ?? null;
            if (!\in_array($data, [Requisito::DATA_RIFERIMENTO, Requisito::DATA_DOPO, Requisito::DATA_LIBERA], true) || !\in_array($scopri, [null, 'iban', 'targa'], true)) {
                throw $errore("requisito \"$rc\": \"data\" o \"scopri\" non validi");
            }
            if (null !== $scopri && !isset($campi[$scopri])) {
                throw $errore("requisito \"$rc\": per scoprire \"$scopri\" serve il campo $scopri");
            }
            if (isset($campi['tipo']) && [] === $tipi) {
                throw $errore("requisito \"$rc\": il campo tipo richiede l'elenco \"tipi\"");
            }
            $riconosci = (array) ($r['riconosci'] ?? []);
            $requisiti[(string) $rc] = new Requisito(
                (string) $rc,
                $r['etichetta'],
                (string) ($r['descrizione'] ?? ''),
                'persona' === $per,
                true === ($r['obbligatorio'] ?? false),
                false !== ($r['somma'] ?? true),
                array_values(array_map('strval', (array) ($riconosci['categorie'] ?? []))),
                self::parole($riconosci['parole'] ?? []),
                $tipi,
                $campi,
                $data,
                $scopri,
                self::parole($riconosci['nomi'] ?? []),
                self::parole($riconosci['senza'] ?? []),
                true === ($r['ancheVecchi'] ?? false),
            );
        }
        if ([] === $requisiti) {
            throw $errore('nessun requisito');
        }

        $riferimento = $dati['riferimento'] ?? 0;
        if (!\is_int($riferimento) || $riferimento < -5 || $riferimento > 0) {
            throw $errore('"riferimento" è un numero di anni tra -5 e 0');
        }
        $rinnovo = $dati['rinnovo'] ?? null;
        if (null !== $rinnovo && (!\is_array($rinnovo) || !\is_string($rinnovo['titolo'] ?? null) || !\is_int($rinnovo['giorni'] ?? null))) {
            throw $errore('"rinnovo" ha "titolo" e "giorni"');
        }

        return new ModelloPratica($codice, $anno, (string) ($dati['titolo'] ?? $codice.' '.$anno), (string) ($dati['descrizione'] ?? ''), $verificato, $fonti, \is_string($dati['note'] ?? null) ? trim($dati['note']) : null, $requisiti, $riferimento, null === $rinnovo ? null : ['titolo' => $rinnovo['titolo'], 'giorni' => $rinnovo['giorni']]);
    }
}
