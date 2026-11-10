<?php

namespace App\Ente\Firma;

use App\Ente\Entity\FirmaCodice;
use App\Ente\Entity\RichiestaDestinatario;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * PDF firmato con codice = documento originale dell'ente + una pagina di
 * attestazione con le evidenze di ogni firma (firmatario, data, canale del
 * codice, impronta del documento, codice di verifica).
 *
 * La pagina si genera con dompdf (font DejaVu, UTF-8) e si unisce con pdfunite
 * (poppler, già nell'immagine per l'OCR). I file temporanei stanno in una
 * cartella privata e si cancellano subito dopo.
 */
final class AttestazioneFirmaPdf
{
    private const TIMEOUT = 60;

    public function __construct(
        #[Autowire('%app.frontend_url%/verifica-firma')]
        private readonly string $urlVerifica,
    ) {
    }

    /** @param list<FirmaCodice> $firme */
    public function componi(string $originale, RichiestaDestinatario $d, array $firme): string
    {
        return $this->unisci($originale, $this->attestazione($d, $firme, hash('sha256', $originale)));
    }

    /** @param list<FirmaCodice> $firme */
    public function attestazione(RichiestaDestinatario $d, array $firme, string $shaOriginale): string
    {
        $r = $d->getRichiesta();
        $tz = new \DateTimeZone('Europe/Rome');
        $html = '<h1>Attestazione di firma elettronica</h1>'
            .'<p class="nota">Firma elettronica con codice monouso (art. 3 Reg. UE 910/2014 e art. 20 c. 1-bis D.Lgs. 82/2005). Le pagine precedenti sono il documento firmato, invariato.</p>'
            .$this->tabella([
                'Ente' => $r->getTenant()->getName(),
                'Documento' => $r->getTitolo().(null !== $r->getAllegato() ? ' ('.$r->getAllegato()->getNomeFile().')' : ''),
                'Riguarda' => $d->getIscritto()->getNomeCompleto(),
                'Impronta SHA-256 del documento' => $shaOriginale,
                'Richiesta inviata il' => $r->getCreatedAt()->setTimezone($tz)->format('d/m/Y H:i'),
            ]);
        foreach ($firme as $i => $f) {
            $html .= sprintf('<h2>Firma %d di %d</h2>', $i + 1, \count($firme)).$this->tabella([
                'Firmatario' => $f->getNomeFirmatario(),
                'Email verificata' => $f->getEmailFirmatario(),
                'Firmato il' => $f->getFirmataIl()?->setTimezone($tz)->format('d/m/Y H:i:s').' (ora italiana)',
                'Metodo' => null !== $f->getUser()
                    ? 'Accesso all\'account CartellaViva + codice monouso inviato all\'email'
                    : 'Link personale + codice monouso inviato all\'email (senza account)',
                'Dichiarazione' => $f->getDichiarazione(),
                'Impronta firmata' => $f->getDocumentoSha256(),
                'Indirizzo IP' => $f->getIp() ?? '-',
                'Codice di verifica' => (string) $f->getCodiceVerifica(),
            ]);
        }
        $html .= sprintf(
            '<p class="nota">Per verificare questa attestazione apri %s e inserisci il codice di verifica: vedrai le stesse informazioni registrate da CartellaViva al momento della firma. '
            .'Il documento è autentico se la sua impronta SHA-256 coincide con quella indicata sopra.</p>',
            htmlspecialchars($this->urlVerifica),
        );

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        // Nessuna risorsa esterna: solo il contenuto qui sopra.
        $options->setIsRemoteEnabled(false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml('<!doctype html><html lang="it"><head><meta charset="utf-8"><style>'
            .'@page{margin:18mm} body{font-family:"DejaVu Sans";font-size:9pt;color:#222}'
            .'h1{font-size:15pt;margin:0 0 4mm} h2{font-size:11pt;margin:5mm 0 2mm}'
            .'.nota{color:#5a5a5a;font-size:8.5pt} table{width:100%;border-collapse:collapse}'
            .'td{padding:1.2mm 0;vertical-align:top} td.k{width:52mm;font-weight:bold} td.v{word-break:break-all}'
            .'</style></head><body>'.$html.'</body></html>');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** @param array<string, string> $righe */
    private function tabella(array $righe): string
    {
        $out = '<table>';
        foreach ($righe as $etichetta => $valore) {
            $out .= sprintf('<tr><td class="k">%s</td><td class="v">%s</td></tr>', htmlspecialchars($etichetta), htmlspecialchars($valore));
        }

        return $out.'</table>';
    }

    /** Unisce i PDF in quest'ordine (pdfunite di poppler). */
    public function unisci(string ...$pdfs): string
    {
        $dir = sys_get_temp_dir().'/fv-firma-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        try {
            $files = [];
            foreach ($pdfs as $i => $contenuto) {
                $files[] = $file = sprintf('%s/%d.pdf', $dir, $i);
                file_put_contents($file, $contenuto);
            }
            $out = $dir.'/firmato.pdf';
            $process = new Process(['pdfunite', ...$files, $out], null, null, null, self::TIMEOUT);
            $process->run();
            if (!$process->isSuccessful() || !is_file($out)) {
                throw new \RuntimeException('Composizione del PDF firmato non riuscita: '.mb_substr(trim($process->getErrorOutput()), 0, 300));
            }

            return (string) file_get_contents($out);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
