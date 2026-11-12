<?php

namespace App\Pratiche;

use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaVoce;
use App\Pratiche\Enum\VoceStato;
use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\Modello\TipoVoce;

/**
 * Controlli, "Cosa manca" e riepilogo di una pratica: tutto deterministico e
 * senza calcoli fiscali. Il riepilogo è la somma dei documenti, non il
 * calcolo della detrazione.
 */
final class ControlliPratica
{
    /**
     * Controlli di una voce.
     *
     * @param array<string, mixed>                                      $valori
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $tutte voci visibili (per i doppioni)
     *
     * @return list<array{codice: string, livello: string, testo: string}>
     */
    public static function voce(ModelloPratica $modello, PraticaVoce $voce, array $valori, array $tutte): array
    {
        $r = $modello->requisito($voce->getRequisito());
        if (null === $r) {
            return [['codice' => 'requisito', 'livello' => 'avviso', 'testo' => 'Questa voce non fa più parte del modello di quest\'anno.']];
        }
        $out = [];
        foreach (ValoriVoce::mancanti($r, $valori) as $etichetta) {
            $out[] = ['codice' => 'mancante', 'livello' => 'avviso', 'testo' => "Manca: $etichetta."];
        }
        if ($r->perPersona && null === $voce->getPersona()) {
            $out[] = ['codice' => 'persona', 'livello' => 'avviso', 'testo' => 'Indica a quale persona si riferisce.'];
        }
        $data = $valori['data'] ?? null;
        $annoAtteso = $r->annoAtteso($modello);
        if (null !== $annoAtteso && \is_string($data) && (int) substr($data, 0, 4) !== $annoAtteso) {
            $out[] = ['codice' => 'anno', 'livello' => 'avviso', 'testo' => sprintf('La data è del %s: per questa pratica conta il %d.', substr($data, 0, 4), $annoAtteso)];
        }
        if (isset($r->campi['tracciabile'])) {
            $tipo = $r->tipo($valori['tipo'] ?? null);
            $tracciabile = $valori['tracciabile'] ?? 'non_risulta';
            if ('si' !== $tracciabile && TipoVoce::RICHIESTO === $tipo?->tracciabile) {
                $out[] = ['codice' => 'tracciabile', 'livello' => 'avviso', 'testo' => 'no' === $tracciabile
                    ? 'Pagata in contanti: per questa spesa serve un pagamento tracciabile (carta, bancomat, bonifico).'
                    : 'Serve un pagamento tracciabile (carta, bancomat, bonifico): tieni la ricevuta del pagamento.'];
            } elseif ('si' !== $tracciabile && TipoVoce::DIPENDE === $tipo?->tracciabile) {
                $out[] = ['codice' => 'tracciabile', 'livello' => 'info', 'testo' => $tipo->nota ?? 'Il pagamento tracciabile serve in alcuni casi.'];
            }
            if (null !== $tipo?->nota && TipoVoce::RICHIESTO === $tipo->tracciabile) {
                $out[] = ['codice' => 'nota', 'livello' => 'info', 'testo' => $tipo->nota];
            }
        }
        // Confronto con un altro valore (es. il saldo della DSU precompilata con quello del documento).
        foreach ($r->campi as $c) {
            $mio = ValoriVoce::centesimi($valori[$c->confronta ?? ''] ?? null);
            $altro = ValoriVoce::centesimi($valori[$c->codice] ?? null);
            if (null !== $c->confronta && null !== $mio && null !== $altro && $mio !== $altro) {
                $out[] = ['codice' => 'confronto', 'livello' => 'avviso', 'testo' => sprintf(
                    '%s: %s € contro %s € del documento. Controlla quale è giusto prima di firmare.',
                    $c->etichetta,
                    number_format($altro / 100, 2, ',', '.'),
                    number_format($mio / 100, 2, ',', '.'),
                )];
            }
        }
        if (VoceStato::ESCLUSA !== $voce->getStato() && null !== ($valori['importo'] ?? null)) {
            foreach ($tutte as $altra) {
                $v = $altra['valori'];
                if ($altra['voce'] !== $voce && VoceStato::ESCLUSA !== $altra['voce']->getStato() && $altra['voce']->getRequisito() === $voce->getRequisito()
                    && ($v['importo'] ?? null) === $valori['importo'] && ($v['data'] ?? null) === ($valori['data'] ?? null)
                    && mb_strtolower((string) ($v['emittente'] ?? '')) === mb_strtolower((string) ($valori['emittente'] ?? ''))) {
                    $out[] = ['codice' => 'doppione', 'livello' => 'avviso', 'testo' => 'Sembra un doppione: c\'è un\'altra voce con lo stesso importo, data ed emittente.'];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Requisiti obbligatori senza voci confermate, per persona.
     *
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $tutte
     *
     * @param array<string, list<string>>                                 $scoperti codici trovati per requisito (CandidatiPratica::scoperti)
     *
     * @return list<array{requisito: string, etichetta: string, personaId: ?string, persona: ?string, dettaglio: ?string, valori: array<string, string>}>
     */
    public static function cosaManca(ModelloPratica $modello, Pratica $pratica, array $tutte, array $scoperti = []): array
    {
        $manca = [];
        // Codici trovati nei documenti (IBAN dei conti, targhe) senza una voce che li riporti.
        foreach ($scoperti as $requisito => $codici) {
            $r = $modello->requisito($requisito);
            if (null === $r || null === $r->scopri) {
                continue;
            }
            $presenti = [];
            foreach ($tutte as $x) {
                if ($x['voce']->getRequisito() === $requisito && VoceStato::ESCLUSA !== $x['voce']->getStato() && \is_string($x['valori'][$r->scopri] ?? null)) {
                    $presenti[$x['valori'][$r->scopri]] = true;
                }
            }
            foreach ($codici as $codice) {
                if (!isset($presenti[$codice]) && !$pratica->nonServe($requisito.':'.$codice, null)) {
                    $manca[] = ['requisito' => $requisito, 'etichetta' => $r->etichetta, 'personaId' => null, 'persona' => null, 'dettaglio' => $codice, 'valori' => [$r->scopri => $codice]];
                }
            }
        }
        foreach ($modello->requisiti as $r) {
            if (!$r->obbligatorio) {
                continue;
            }
            $persone = $r->perPersona ? $pratica->getPersone() : [null];
            foreach ($persone as $p) {
                if ($pratica->nonServe($r->codice, $p)) {
                    continue;
                }
                $c = array_filter($tutte, static fn (array $x) => VoceStato::CONFERMATA === $x['voce']->getStato() && $x['voce']->getRequisito() === $r->codice && (null === $p || $x['voce']->getPersona() === $p));
                if ([] === $c) {
                    $manca[] = ['requisito' => $r->codice, 'etichetta' => $r->etichetta, 'personaId' => $p?->getId()->toRfc4122(), 'persona' => $p?->getDisplayName(), 'dettaglio' => null, 'valori' => []];
                }
            }
        }

        return $manca;
    }

    /**
     * Voci confermate l'anno prima (stesso requisito, emittente e persona) che
     * quest'anno mancano: "l'anno scorso c'era la quietanza della polizza vita".
     *
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $prima voci dell'anno prima visibili all'utente
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $ora
     *
     * @return list<array{requisito: string, etichetta: string, emittente: ?string, persona: ?string}>
     */
    public static function annoPrima(ModelloPratica $modello, array $prima, array $ora): array
    {
        $chiave = static fn (array $x): string => $x['voce']->getRequisito().'|'.mb_strtolower(trim((string) ($x['valori']['emittente'] ?? ''))).'|'.($x['voce']->getPersona()?->getId()->toRfc4122() ?? '');
        $presenti = [];
        foreach ($ora as $x) {
            if (VoceStato::ESCLUSA !== $x['voce']->getStato()) {
                $presenti[$chiave($x)] = true;
            }
        }
        $out = [];
        foreach ($prima as $x) {
            $k = $chiave($x);
            $r = $modello->requisito($x['voce']->getRequisito());
            if (VoceStato::CONFERMATA !== $x['voce']->getStato() || isset($presenti[$k]) || isset($out[$k]) || null === $r || ($r->somma && null === ($x['valori']['emittente'] ?? null))) {
                continue;
            }
            $out[$k] = ['requisito' => $r->codice, 'etichetta' => $r->etichetta, 'emittente' => $x['valori']['emittente'] ?? null, 'persona' => $x['voce']->getPersona()?->getDisplayName()];
        }

        return array_values($out);
    }

    /**
     * Somme delle voci confermate per requisito, per tipo e per persona.
     *
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $tutte
     *
     * @return array{totale: string, requisiti: list<array<string, mixed>>, persone: list<array{personaId: ?string, persona: string, totale: string}>}
     */
    public static function riepilogo(ModelloPratica $modello, array $tutte): array
    {
        $perRequisito = [];
        $perTipo = [];
        $perPersona = [];
        $totale = 0;
        foreach ($tutte as $x) {
            $r = $modello->requisito($x['voce']->getRequisito());
            $c = ValoriVoce::centesimi($x['valori']['importo'] ?? null);
            if (null === $r || !$r->somma || null === $c || VoceStato::CONFERMATA !== $x['voce']->getStato()) {
                continue;
            }
            $totale += $c;
            $perRequisito[$r->codice] = ($perRequisito[$r->codice] ?? 0) + $c;
            $tipo = (string) ($x['valori']['tipo'] ?? '');
            $perTipo[$r->codice][$tipo] = ($perTipo[$r->codice][$tipo] ?? 0) + $c;
            $p = $x['voce']->getPersona();
            $kp = $p?->getId()->toRfc4122() ?? '';
            $perPersona[$kp] ??= ['personaId' => $p?->getId()->toRfc4122(), 'persona' => $p?->getDisplayName() ?? 'Famiglia', 'c' => 0];
            $perPersona[$kp]['c'] += $c;
        }
        $requisiti = [];
        foreach ($modello->requisiti as $r) {
            if (!isset($perRequisito[$r->codice])) {
                continue;
            }
            $tipi = [];
            foreach ($perTipo[$r->codice] as $t => $c) {
                $tipi[] = ['tipo' => '' === $t ? null : $t, 'etichetta' => $r->tipo($t)->etichetta ?? $r->etichetta, 'totale' => ValoriVoce::formatta($c)];
            }
            $requisiti[] = ['requisito' => $r->codice, 'etichetta' => $r->etichetta, 'totale' => ValoriVoce::formatta($perRequisito[$r->codice]), 'tipi' => $tipi];
        }

        return [
            'totale' => ValoriVoce::formatta($totale),
            'requisiti' => $requisiti,
            'persone' => array_values(array_map(static fn (array $p) => ['personaId' => $p['personaId'], 'persona' => $p['persona'], 'totale' => ValoriVoce::formatta($p['c'])], $perPersona)),
        ];
    }
}
