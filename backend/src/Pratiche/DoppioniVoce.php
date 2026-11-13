<?php

namespace App\Pratiche;

use App\Pratiche\Entity\Pratica;
use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\Modello\Requisito;
use App\Pratiche\Repository\PraticaRepository;
use App\Tenancy\Entity\MembroFamiglia;

/**
 * La stessa cosa trovata in più documenti (lo stesso conto in dodici estratti,
 * lo stesso immobile in tre visure, due copie dello stato di famiglia) si
 * propone una volta sola: se la pratica ha già una voce uguale, proposta,
 * confermata o esclusa, la nuova si scarta. Le spese no: due scontrini dello
 * stesso importo sono due spese.
 *
 * Uguale vuol dire stesso requisito, stessa persona e stessa chiave:
 * l'IBAN, la targa, oppure (requisiti che non si sommano) tipo e importo.
 */
final class DoppioniVoce
{
    public function __construct(
        private readonly PraticaRepository $pratiche,
        private readonly PraticaCifratura $cifratura,
    ) {
    }

    /** Chiave di confronto, o null se la voce non ha doppioni possibili (una spesa). */
    public static function chiave(Requisito $r, ?MembroFamiglia $persona, array $valori): ?string
    {
        $chi = $r->codice.'|'.($persona?->getId()->toRfc4122() ?? 'famiglia').'|';
        if ('' !== (string) ($valori['iban'] ?? '')) {
            return $chi.'iban:'.$valori['iban'];
        }
        if ('' !== (string) ($valori['targa'] ?? '')) {
            return $chi.'targa:'.$valori['targa'];
        }
        if ($r->somma) {
            return null;
        }

        return $chi.'tipo:'.($valori['tipo'] ?? '').'|importo:'.($valori['importo'] ?? '');
    }

    /**
     * @param list<array{requisito: string, persona: ?MembroFamiglia, valori: array<string, string>}> $voci nuove
     *
     * @return list<array{requisito: string, persona: ?MembroFamiglia, valori: array<string, string>}> senza quelle già nella pratica
     */
    public function nuove(ModelloPratica $modello, Pratica $pratica, array $voci): array
    {
        if ([] === $voci) {
            return [];
        }
        $presenti = [];
        foreach ($this->pratiche->voci($pratica) as $v) {
            $r = $modello->requisito($v->getRequisito());
            $k = null === $r ? null : self::chiave($r, $v->getPersona(), array_map('strval', $this->cifratura->decifra($v)));
            if (null !== $k) {
                $presenti[$k] = true;
            }
        }

        $tenute = [];
        foreach ($voci as $v) {
            $r = $modello->requisito($v['requisito']);
            $k = null === $r ? null : self::chiave($r, $v['persona'], $v['valori']);
            if (null !== $k && isset($presenti[$k])) {
                continue;
            }
            if (null !== $k) {
                $presenti[$k] = true;
            }
            $tenute[] = $v;
        }

        return $tenute;
    }
}
