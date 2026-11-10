<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\Message\AnteprimaDocumentoMessage;
use App\Documenti\Message\OcrDocumentoMessage;
use App\Documenti\MessageHandler\OcrDocumentoHandler;
use App\Documenti\TestoSemplice;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Process\Process;

/**
 * Formati aggiunti dopo quelli Office: TXT, GIF e WEBP (testo e anteprima
 * dall'originale), Pages, Numbers e Keynote (come i documenti Office: PDF di
 * anteprima e testo da quel PDF), ZIP (solo conservati: mai aperti).
 */
final class FormatiAggiuntiviTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
        $this->token = $this->login($this->owner);
    }

    public function testTxtInWindows1252BecomesSearchableUtf8Text(): void
    {
        $txt = "Caff\xE8 e ricevuta del \xABbar Centrale\xBB\r\nTotale 3,20 euro\r\n";
        $doc = $this->carica($txt, 'scontrino.txt');
        self::assertSame('text/plain', $doc['mimeType']);
        self::assertSame('non_serve', $doc['anteprimaStato'], 'il testo si mostra così com\'è');

        $dettaglio = $this->conOcr($doc['id']);
        self::assertSame('completato', $dettaglio['ocrStatus']);
        self::assertSame("Caffè e ricevuta del «bar Centrale»\nTotale 3,20 euro", $dettaglio['ocrText']);
        // Anteprima in UTF-8 (come dichiara la risposta); il file scaricato resta l'originale.
        self::assertSame(["Caffè e ricevuta del «bar Centrale»\r\nTotale 3,20 euro\r\n", 'text/plain; charset=UTF-8'], $this->scarica($doc['id'], 'anteprima'));
        self::assertSame($txt, $this->scarica($doc['id'], 'file')[0]);
    }

    public function testGifAndWebpAreReadWithOcrAndAreTheirOwnPreview(): void
    {
        foreach (['gif' => 'image/gif', 'webp' => 'image/webp'] as $formato => $mime) {
            $immagine = $this->converti($this->fixture('ocr_fattura.png'), $formato);
            $doc = $this->carica($immagine, "fattura.$formato");
            self::assertSame($mime, $doc['mimeType']);
            self::assertSame('non_serve', $doc['anteprimaStato']);

            $dettaglio = $this->conOcr($doc['id']);
            self::assertSame('completato', $dettaglio['ocrStatus'], $formato);
            self::assertStringContainsString('FATTURA', $dettaglio['ocrText'], $formato);
            self::assertSame([$immagine, $mime], $this->scarica($doc['id'], 'anteprima'));
        }
    }

    public function testIworkDocumentsAreRecognisedFromContentAndConvertedLikeOffice(): void
    {
        // Il tipo lo decide l'estensione, ma solo se il contenuto è davvero un documento iWork.
        $doc = $this->carica($this->iwork(['Index/Tables/DataList-1.iwa']), 'bilancio.numbers');
        self::assertSame('application/vnd.apple.numbers', $doc['mimeType']);
        self::assertSame('bilancio.numbers', $doc['nomeFile']);
        self::assertSame('in_coda', $doc['anteprimaStato']);
        self::assertSame('in_coda', $doc['ocrStatus']);
        self::assertSame([AnteprimaDocumentoMessage::class], $this->inCoda(), 'come per Office: prima il PDF, poi il testo');

        // Senza estensione: dalle parti tipiche (diapositive = Keynote), e il nome prende l'estensione giusta.
        $doc = $this->carica($this->iwork(['Index/Slide-12.iwa', 'Index/Tables/Tile-3.iwa']), 'presentazione');
        self::assertSame('application/vnd.apple.keynote', $doc['mimeType']);
        self::assertSame('presentazione.key', $doc['nomeFile']);

        $doc = $this->carica($this->iwork([]), 'lettera.template');
        self::assertSame('application/vnd.apple.pages', $doc['mimeType']);
        self::assertSame('lettera.pages', $doc['nomeFile']);

        // Un .pages che non è un documento iWork non passa per l'estensione.
        $zip = $this->zip(['nota.txt' => 'ciao']);
        self::assertSame('application/zip', $this->carica($zip, 'finto.pages')['mimeType']);
    }

    public function testZipIsStoredButNeverOpened(): void
    {
        // Dentro c'è un documento Pages non compresso: i suoi nomi interni non contano, resta uno ZIP.
        $zip = $this->zip(['documenti/lettera.pages' => $this->iwork([]), 'foto.png' => $this->fixture('ocr_fattura.png')]);
        $doc = $this->carica($zip, 'archivio.zip');
        self::assertSame('application/zip', $doc['mimeType']);
        self::assertSame('non_applicabile', $doc['ocrStatus']);
        self::assertSame('non_serve', $doc['anteprimaStato']);
        self::assertSame([], $this->inCoda(), 'niente OCR né anteprima');

        $esito = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}/anteprima", token: $this->token);
        $this->assertStatus(409);
        self::assertStringContainsString('ZIP non hanno anteprima', $esito['anteprimaErrore']);
        self::assertSame([$zip, 'application/zip'], $this->scarica($doc['id'], 'file'));
    }

    public function testPlainTextDecoding(): void
    {
        self::assertSame('però', TestoSemplice::inUtf8("\xEF\xBB\xBFperò"));
        self::assertSame("però\r\nsì", TestoSemplice::inUtf8("\xFF\xFE".mb_convert_encoding("però\r\nsì", 'UTF-16LE', 'UTF-8')));
        self::assertSame('però', TestoSemplice::inUtf8("\xFE\xFF".mb_convert_encoding('però', 'UTF-16BE', 'UTF-8')));
        self::assertSame('però', TestoSemplice::inUtf8("per\xF2"));
        self::assertSame('già UTF-8', TestoSemplice::inUtf8('già UTF-8'));
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function carica(string $contenuto, string $nome): array
    {
        $doc = $this->upload($this->tid(), $this->token, $contenuto, $nome, ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->assertStatus(201);

        return (array) $doc;
    }

    /** @return array<string, mixed> il dettaglio dopo l'OCR */
    private function conOcr(string $id): array
    {
        static::getContainer()->get(OcrDocumentoHandler::class)(new OcrDocumentoMessage($id));

        return (array) $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$id", token: $this->token);
    }

    /** @return array{0: string, 1: string} corpo e Content-Type */
    private function scarica(string $id, string $cosa): array
    {
        $this->client->request('GET', "/api/tenants/{$this->tid()}/documenti/$id/$cosa", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        $this->assertStatus(200);
        $r = $this->client->getResponse();

        return [(string) $r->getContent(), (string) $r->headers->get('Content-Type')];
    }

    /**
     * Struttura di un documento iWork: Index/Document.iwa più le parti date.
     *
     * @param list<string> $parti
     */
    private function iwork(array $parti): string
    {
        $voci = ['Index/Document.iwa' => 'iwa', 'Index/DocumentStylesheet.iwa' => 'iwa', 'Metadata/Properties.plist' => 'plist'];
        foreach ($parti as $parte) {
            $voci[$parte] = 'iwa';
        }

        return $this->zip($voci);
    }

    /** @param array<string, string> $voci nome => contenuto, salvati senza compressione */
    private function zip(array $voci): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($voci as $nome => $contenuto) {
            $zip->addFromString($nome, $contenuto);
            $zip->setCompressionName($nome, \ZipArchive::CM_STORE);
        }
        $zip->close();
        $contenuto = (string) file_get_contents($path);
        unlink($path);

        return $contenuto;
    }

    private function converti(string $png, string $formato): string
    {
        $p = new Process(['convert', 'png:-', $formato.':-'], null, null, $png, 30);
        $p->mustRun();

        return $p->getOutput();
    }

    /** @return list<class-string> */
    private function inCoda(): array
    {
        /** @var InMemoryTransport $t */
        $t = static::getContainer()->get('messenger.transport.async');
        $messaggi = array_map(static fn ($e) => $e->getMessage()::class, $t->getSent());
        $t->reset();

        return $messaggi;
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }
}
