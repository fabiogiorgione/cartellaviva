<?php

namespace App\Pratiche\Allenamento;

use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaVoce;
use App\Pratiche\Enum\VoceStato;
use App\Pratiche\CandidatiPratica;
use App\Pratiche\EstrattoreVoci;
use App\Pratiche\Modello\ModelliPratica;
use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\Modello\Requisito;
use App\Pratiche\PraticaCifratura;
use App\Pratiche\Repository\PraticaRepository;
use App\Pratiche\ValoriVoce;
use App\Tenancy\Entity\MembroFamiglia;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Esempi per misurare e allenare l'analisi dei documenti, dalle pratiche già
 * riviste: per ogni documento analizzato, il testo e la risposta giusta.
 *
 * - Positivi: documenti con voci confermate (anche corrette a mano): la
 *   risposta giusta sono quelle voci.
 * - Negativi: documenti con proposte tutte escluse ("qui non c'è nulla").
 *   Con [negativi] anche i documenti analizzati senza proposte; attenzione:
 *   se una voce mancata è stata aggiunta a mano senza collegarla al documento,
 *   quel documento risulta negativo per sbaglio.
 * - Si saltano i documenti con proposte ancora da vedere (non rivisti).
 *
 * Gira da console, sul server: legge le voci direttamente (niente controllo
 * dei permessi dell'utente) e decifra il testo in memoria.
 */
class EsempiPratica
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly ModelliPratica $modelli,
        private readonly PraticaRepository $pratiche,
        private readonly PraticaCifratura $cifratura,
    ) {
    }

    /**
     * @return iterable<array{pratica: Pratica, modello: ModelloPratica, documento: Documento, testo: string, requisiti: list<Requisito>, oro: list<array{requisito: string, persona: ?MembroFamiglia, valori: array<string, string>}>, nonCandidati: list<string>}>
     */
    public function esempi(Pratica $pratica, bool $negativi = false, ?string $documentoId = null): iterable
    {
        $modello = $this->modelli->trova($pratica->getModello(), $pratica->getAnno())
            ?? throw new \RuntimeException(sprintf('Mancano le regole %s %d.', $pratica->getModello(), $pratica->getAnno()));
        $perDocumento = [];
        foreach ($this->pratiche->voci($pratica) as $v) {
            if (null !== $v->getDocumento()) {
                $perDocumento[$v->getDocumento()->getId()->toRfc4122()][] = $v;
            }
        }
        $analizzati = array_flip($this->pratiche->documentiAnalizzati($pratica));
        $documenti = [];
        foreach ($this->pratiche->voci($pratica) as $v) {
            if (null !== $v->getDocumento()) {
                $documenti[$v->getDocumento()->getId()->toRfc4122()] = $v->getDocumento();
            }
        }
        if ($negativi) {
            foreach ($this->documentiAnalizzati($pratica) as $d) {
                $documenti[$d->getId()->toRfc4122()] ??= $d;
            }
        }
        foreach ($documenti as $id => $d) {
            if (null !== $documentoId && $documentoId !== $id) {
                continue;
            }
            /** @var list<PraticaVoce> $voci */
            $voci = $perDocumento[$id] ?? [];
            if ([] !== array_filter($voci, static fn (PraticaVoce $v) => VoceStato::PROPOSTA === $v->getStato())) {
                continue;
            }
            $confermate = array_values(array_filter($voci, static fn (PraticaVoce $v) => VoceStato::CONFERMATA === $v->getStato()));
            if ([] === $confermate && [] === $voci && !($negativi && isset($analizzati[$id]))) {
                continue;
            }
            $testo = trim((string) $this->documents->readOcrText($d));
            if ('' === $testo) {
                continue;
            }
            $requisiti = EstrattoreVoci::candidati($modello, $d->getCategoria()->getSlug(), $testo, $d->getNomeFileOriginale(), CandidatiPratica::vecchio($modello, $d, $pratica), $pratica->analizzaTutti());
            $oro = [];
            $nonCandidati = [];
            foreach ($confermate as $v) {
                $r = $modello->requisito($v->getRequisito());
                if (null === $r) {
                    continue;
                }
                if (!\in_array($r, $requisiti, true)) {
                    // Il modello non l'avrebbe nemmeno visto: mancano parole chiave o categoria.
                    $nonCandidati[] = $r->codice;
                    $requisiti[] = $r;
                }
                $valori = array_filter(array_map('strval', $this->cifratura->decifra($v)), static fn ($x) => '' !== $x);
                unset($valori['nota']);
                $oro[] = ['requisito' => $r->codice, 'persona' => $v->getPersona(), 'valori' => $valori];
            }

            yield ['pratica' => $pratica, 'modello' => $modello, 'documento' => $d, 'testo' => $testo, 'requisiti' => $requisiti, 'oro' => $oro, 'nonCandidati' => array_values(array_unique($nonCandidati))];
        }
    }

    /**
     * La risposta giusta nel formato che chiediamo al modello: campi del
     * requisito, importi e date come sono scritti nel testo.
     *
     * @param list<array{requisito: string, persona: ?MembroFamiglia, valori: array<string, string>}> $oro
     * @param list<Requisito>                                                                    $requisiti
     * @param callable(string): string                                                           $nome     nome della persona come va scritto (anonimizzato o no)
     *
     * @return array{voci: list<array<string, string>>}
     */
    public static function risposta(ModelloPratica $modello, array $oro, array $requisiti, string $testo, callable $nome): array
    {
        $campiChiesti = [];
        foreach ($requisiti as $r) {
            foreach ($r->campi as $c) {
                if (!$c->soloUtente && 'scelta' !== $c->tipo) {
                    $campiChiesti[$c->codice] = $c;
                }
            }
        }
        $voci = [];
        foreach ($oro as $o) {
            $r = $modello->requisito($o['requisito']);
            $v = ['requisito' => $o['requisito'], 'tipo' => $o['valori']['tipo'] ?? '', 'persona' => null !== $o['persona'] ? $nome($o['persona']->getDisplayName()) : ''];
            foreach ($campiChiesti as $codice => $c) {
                $x = isset($r->campi[$codice]) ? ($o['valori'][$codice] ?? '') : '';
                $v[$codice] = match ($c->tipo) {
                    'importo' => '' === $x ? '' : self::importoComeNelTesto($x, $testo),
                    'data' => '' === $x ? '' : self::dataComeNelTesto($x, $testo),
                    default => $x,
                };
            }
            $voci[] = $v;
        }

        return ['voci' => $voci];
    }

    private static function importoComeNelTesto(string $valore, string $testo): string
    {
        $c = ValoriVoce::centesimi($valore);
        if (null === $c) {
            return $valore;
        }
        $intero = intdiv($c, 100);
        $dec = sprintf('%02d', $c % 100);
        foreach ([number_format($intero, 0, '', '.').','.$dec, $intero.','.$dec, $intero.'.'.$dec] as $forma) {
            if (str_contains($testo, $forma)) {
                return $forma;
            }
        }

        return $intero.','.$dec;
    }

    private static function dataComeNelTesto(string $data, string $testo): string
    {
        [$a, $m, $g] = array_map('intval', explode('-', $data) + [0, 0, 0]);
        foreach (['/', '.', '-'] as $sep) {
            foreach ([sprintf('%02d%s%02d%s%04d', $g, $sep, $m, $sep, $a), sprintf('%d%s%d%s%04d', $g, $sep, $m, $sep, $a)] as $forma) {
                if (str_contains($testo, $forma)) {
                    return $forma;
                }
            }
        }

        return sprintf('%02d/%02d/%04d', $g, $m, $a);
    }

    /** @return list<Documento> */
    private function documentiAnalizzati(Pratica $pratica): array
    {
        $ids = $this->pratiche->documentiAnalizzati($pratica);

        return [] === $ids ? [] : $this->em->getRepository(Documento::class)->findBy(['id' => array_map(static fn (string $id) => Uuid::fromString($id), $ids)]);
    }
}
