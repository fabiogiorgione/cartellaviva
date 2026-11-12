<?php

namespace App\Pratiche;

use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaVoce;
use App\Pratiche\Enum\VoceStato;
use App\Pratiche\Modello\ModelloPratica;
use Dompdf\Dompdf;
use Dompdf\Options;
use ZipStream\ZipStream;

/**
 * Pacchetto di una pratica, generato al momento e mai conservato (salvo
 * "Archivia"): i documenti delle voci confermate in cartelle per persona e
 * requisito, più "Riepilogo.pdf" con le voci, le somme (dei documenti, non il
 * calcolo della detrazione), i controlli e cosa manca.
 */
class PacchettoPratica
{
    public function __construct(private readonly DocumentService $documents)
    {
    }

    public static function nomeFile(ModelloPratica $modello): string
    {
        return self::pulisci($modello->titolo).'.zip';
    }

    /**
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $voci voci visibili
     * @param list<array<string, mixed>>                                   $cosaManca
     * @param resource                                                     $output
     */
    public function scrivi(ModelloPratica $modello, Pratica $pratica, array $voci, array $cosaManca, $output): void
    {
        $confermate = array_values(array_filter($voci, static fn (array $x) => VoceStato::CONFERMATA === $x['voce']->getStato()));
        $zip = new ZipStream(outputStream: $output, sendHttpHeaders: false, defaultEnableZeroHeader: true, flushOutput: false);
        $zip->addFile(fileName: 'Riepilogo.pdf', data: $this->riepilogo($modello, $pratica, $confermate, $voci, $cosaManca));
        $usati = [];
        $messi = [];
        foreach ($confermate as $x) {
            $d = $x['voce']->getDocumento();
            if (null === $d) {
                continue;
            }
            $r = $modello->requisito($x['voce']->getRequisito());
            $cartella = self::pulisci($x['voce']->getPersona()?->getDisplayName() ?? 'Famiglia').'/'.self::pulisci($r->etichetta ?? $x['voce']->getRequisito());
            $chiave = $cartella.'|'.$d->getId()->toRfc4122();
            if (isset($messi[$chiave])) {
                continue;
            }
            $messi[$chiave] = true;
            $nome = $cartella.'/'.self::nomeDocumento($x['valori'], $d);
            $base = $nome;
            for ($i = 2; isset($usati[$nome]); ++$i) {
                $nome = (string) preg_replace('/(\.[a-z0-9]+)?$/i', " ($i)$1", $base, 1);
            }
            $usati[$nome] = true;
            $zip->addFile(fileName: $nome, data: $this->documents->readContent($d));
        }
        $zip->finish();
    }

    /** "2025-03-12 Farmacia Centrale 45,90.pdf" */
    private static function nomeDocumento(array $valori, Documento $d): string
    {
        $ext = pathinfo($d->getNomeFileOriginale(), \PATHINFO_EXTENSION);
        $parti = array_filter([
            \is_string($valori['data'] ?? null) ? $valori['data'] : null,
            \is_string($valori['emittente'] ?? null) ? $valori['emittente'] : $d->getTitolo(),
            \is_string($valori['importo'] ?? null) ? str_replace('.', ',', $valori['importo']) : null,
        ]);

        return mb_substr(self::pulisci(implode(' ', $parti)), 0, 120).('' !== $ext ? '.'.strtolower($ext) : '');
    }

    private static function pulisci(string $s): string
    {
        $s = trim((string) preg_replace('/[^\p{L}\p{N} ,.\-()°&\']+/u', ' ', $s));
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return '' === $s ? 'Senza nome' : $s;
    }

    /**
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $confermate
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $tutte
     * @param list<array<string, mixed>>                                   $cosaManca
     */
    public function riepilogo(ModelloPratica $modello, Pratica $pratica, array $confermate, array $tutte, array $cosaManca): string
    {
        $h = static fn (?string $s): string => htmlspecialchars((string) $s, \ENT_QUOTES);
        $euro = static fn (?string $v): string => null === $v ? '' : number_format((float) $v, 2, ',', '.').' €';
        $dataIt = static fn (?string $d): string => null === $d ? '' : implode('/', array_reverse(explode('-', $d)));
        $somme = ControlliPratica::riepilogo($modello, $confermate);

        $html = '<h1>'.$h($modello->titolo).'</h1>'
            .'<p class="nota">Preparato con CartellaViva il '.(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome')))->format('d/m/Y').'. '
            .'Persone: '.$h(implode(', ', array_map(static fn ($p) => $p->getDisplayName(), $pratica->getPersone()))).'. '
            .'Le somme sono quelle dei documenti, <b>non il calcolo della detrazione</b>: i calcoli li fa il CAF o la dichiarazione precompilata.</p>';
        if ($modello->provvisorio()) {
            $html .= '<p class="avviso">Regole provvisorie: le istruzioni ufficiali di quest\'anno non sono ancora state ricontrollate.</p>';
        }

        $html .= '<h2>Somme per persona</h2><table><tr><th>Persona</th><th class="n">Totale</th></tr>';
        foreach ($somme['persone'] as $p) {
            $html .= '<tr><td>'.$h($p['persona']).'</td><td class="n">'.$euro($p['totale']).'</td></tr>';
        }
        $html .= '<tr class="tot"><td>Totale</td><td class="n">'.$euro($somme['totale']).'</td></tr></table>';
        $html .= '<h2>Somme per tipo</h2><table><tr><th>Voce</th><th>Tipo</th><th class="n">Totale</th></tr>';
        foreach ($somme['requisiti'] as $r) {
            foreach ($r['tipi'] as $t) {
                $html .= '<tr><td>'.$h($r['etichetta']).'</td><td>'.$h($t['etichetta']).'</td><td class="n">'.$euro($t['totale']).'</td></tr>';
            }
        }
        $html .= '</table>';

        foreach ($modello->requisiti as $r) {
            $righe = array_filter($confermate, static fn (array $x) => $x['voce']->getRequisito() === $r->codice);
            if ([] === $righe) {
                continue;
            }
            $html .= '<h2>'.$h($r->etichetta).'</h2><table><tr><th>Persona</th><th>Data</th><th>Tipo</th><th>Emittente</th><th>Tracciabile</th><th class="n">Importo</th></tr>';
            foreach ($righe as $x) {
                $v = $x['valori'];
                $controlli = ControlliPratica::voce($modello, $x['voce'], $v, $tutte);
                $html .= '<tr><td>'.$h($x['voce']->getPersona()?->getDisplayName() ?? 'Famiglia').'</td><td>'.$dataIt($v['data'] ?? null).'</td>'
                    .'<td>'.$h($r->tipo($v['tipo'] ?? null)?->etichetta).'</td><td>'.$h($v['emittente'] ?? $x['voce']->getDocumento()?->getTitolo()).'</td>'
                    .'<td>'.$h(['si' => 'Sì', 'no' => 'No (contanti)', 'non_risulta' => 'Non risulta'][$v['tracciabile'] ?? ''] ?? '').'</td><td class="n">'.$euro($v['importo'] ?? null).'</td></tr>';
                foreach ($controlli as $c) {
                    if ('avviso' === $c['livello']) {
                        $html .= '<tr><td></td><td colspan="5" class="avviso">'.$h($c['testo']).'</td></tr>';
                    }
                }
            }
            $html .= '</table>';
        }

        if ([] !== $cosaManca) {
            $html .= '<h2>Cosa manca</h2><ul>';
            foreach ($cosaManca as $m) {
                $html .= '<li>'.$h($m['etichetta'].(null !== ($m['persona'] ?? null) ? ' · '.$m['persona'] : '')).'</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<p class="nota">Fonti delle regole: '.$h(implode('; ', array_map(static fn ($f) => $f['titolo'].' ('.$f['url'].')', $modello->fonti))).'.</p>';

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml('<!doctype html><html lang="it"><head><meta charset="utf-8"><style>'
            .'@page{margin:16mm} body{font-family:"DejaVu Sans";font-size:8.5pt;color:#222}'
            .'h1{font-size:15pt;margin:0 0 3mm} h2{font-size:11pt;margin:6mm 0 2mm}'
            .'.nota{color:#5a5a5a;font-size:8pt} .avviso{color:#a15c00;font-size:8pt}'
            .'table{width:100%;border-collapse:collapse} th{text-align:left;border-bottom:1px solid #999;padding:1mm}'
            .'td{padding:1mm;border-bottom:1px solid #e3e3e3;vertical-align:top} .n{text-align:right;white-space:nowrap} tr.tot td{font-weight:bold}'
            .'</style></head><body>'.$html.'</body></html>');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
