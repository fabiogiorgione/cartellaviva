<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\DocumentStorage;
use App\Documenti\Entity\Documento;
use App\Documenti\Message\AnteprimaDocumentoMessage;
use App\Documenti\Message\OcrDocumentoMessage;
use App\Documenti\MessageHandler\AnteprimaDocumentoHandler;
use App\Documenti\MessageHandler\OcrDocumentoHandler;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tests\Support\FakeConvertitore;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Scheda "Anteprima": i documenti Office restano nel formato originale, il
 * server ne ricava un PDF (cifrato come l'originale) solo per mostrarli; il
 * testo si legge da quel PDF. PDF e immagini sono l'anteprima di sé stessi.
 */
final class AnteprimaDocumentoTest extends ApiTestCase
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

    public function testDocxKeepsOriginalAndGetsEncryptedPdfPreviewThenText(): void
    {
        $docx = $this->fixture('contratto.docx');
        $doc = $this->upload($this->tid(), $this->token, $docx, 'contratto.docx', ['categoriaId' => (string) $this->categoria('salute')->getId()]);
        $this->assertStatus(201);
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $doc['mimeType']);
        self::assertTrue($doc['cifrato']);
        self::assertSame('in_coda', $doc['anteprimaStato']);
        // Prima l'anteprima: l'OCR del DOCX parte solo dal PDF.
        self::assertSame([AnteprimaDocumentoMessage::class], $this->inCoda());

        $this->client->request('GET', $this->uri($doc['id'], 'anteprima'), server: $this->auth());
        $this->assertStatus(202);

        $this->anteprimaHandler()(new AnteprimaDocumentoMessage($doc['id']));
        // Pronta l'anteprima, parte l'OCR sul PDF ricavato (la coda si svuota a ogni richiesta: si guarda subito).
        self::assertContains(OcrDocumentoMessage::class, $this->inCoda());
        $entity = $this->documento($doc['id']);
        self::assertSame('pronta', $entity->getAnteprimaStato()->value);
        self::assertStringStartsNotWith('%PDF', $this->storage()->read((string) $entity->getAnteprimaKey()), 'la copia di un documento cifrato è cifrata');

        [$corpo, $tipo] = $this->scarica($doc['id'], 'anteprima');
        self::assertSame('application/pdf', $tipo);
        self::assertStringStartsWith('%PDF', $corpo);
        [$originale, $tipoOriginale] = $this->scarica($doc['id'], 'file');
        self::assertSame($docx, $originale, 'il file dell\'utente resta il DOCX');
        self::assertSame($doc['mimeType'], $tipoOriginale);

        $this->ocrHandler()(new OcrDocumentoMessage($doc['id']));
        $dettaglio = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}", token: $this->token);
        self::assertSame('completato', $dettaglio['ocrStatus']);
        self::assertStringContainsString('Anteprima di contratto.docx', $dettaglio['ocrText']);
        self::assertSame('application/pdf', $dettaglio['anteprimaMime']);
    }

    public function testConverterNotConfiguredIsExplicitAndRetryable(): void
    {
        $this->convertitore()->configurato = false;
        $doc = $this->upload($this->tid(), $this->token, $this->fixture('contratto.docx'), 'contratto.docx', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->anteprimaHandler()(new AnteprimaDocumentoMessage($doc['id']));

        $esito = $this->request('GET', $this->uri($doc['id'], 'anteprima'), token: $this->token);
        $this->assertStatus(409);
        self::assertSame('fallita', $esito['anteprimaStato']);
        self::assertStringContainsString('non configurato', $esito['anteprimaErrore']);
        self::assertSame('fallito', $this->documento($doc['id'])->getOcrStatus()->value);

        // Configurato il convertitore, si rigenera: anteprima e poi testo.
        $this->convertitore()->configurato = true;
        $this->request('POST', $this->uri($doc['id'], 'anteprima'), token: $this->token);
        $this->assertStatus(202);
        $this->anteprimaHandler()(new AnteprimaDocumentoMessage($doc['id']));
        self::assertSame('pronta', $this->documento($doc['id'])->getAnteprimaStato()->value);
        self::assertSame('in_coda', $this->documento($doc['id'])->getOcrStatus()->value);
    }

    public function testPdfAndImagesAreTheirOwnPreviewAndAccessIsChecked(): void
    {
        $png = $this->fixture('ocr_fattura.png');
        $doc = $this->upload($this->tid(), $this->token, $png, 'bolletta.png', ['categoriaId' => (string) $this->categoria('bollette')->getId()]);
        self::assertSame('non_serve', $doc['anteprimaStato']);
        self::assertSame('image/png', $doc['anteprimaMime']);
        self::assertSame(0, $this->convertitore()->chiamate);
        self::assertNotContains(AnteprimaDocumentoMessage::class, $this->inCoda());
        self::assertSame([$png, 'image/png'], $this->scarica($doc['id'], 'anteprima'));

        // Chi non è della famiglia non vede nulla.
        $estraneo = $this->login($this->createUser('estraneo@example.com'));
        $this->client->request('GET', $this->uri($doc['id'], 'anteprima'), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$estraneo]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
    }

    public function testMovingToSensitiveCategoryEncryptsThePreviewToo(): void
    {
        $doc = $this->upload($this->tid(), $this->token, $this->fixture('contratto.docx'), 'contratto.docx', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->anteprimaHandler()(new AnteprimaDocumentoMessage($doc['id']));
        $prima = (string) $this->documento($doc['id'])->getAnteprimaKey();
        self::assertStringStartsWith('%PDF', $this->storage()->read($prima), 'categoria non sensibile: in chiaro');

        $this->patch("/api/tenants/{$this->tid()}/documenti/{$doc['id']}", ['categoriaId' => (string) $this->categoria('salute')->getId()], $this->token);
        $this->assertStatus(200);
        $entity = $this->documento($doc['id']);
        self::assertTrue($entity->isCifrato());
        self::assertStringStartsNotWith('%PDF', $this->storage()->read((string) $entity->getAnteprimaKey()));
        self::assertStringStartsWith('%PDF', $this->scarica($doc['id'], 'anteprima')[0]);
        if ($prima !== $entity->getAnteprimaKey()) {
            self::assertFalse($this->storage()->exists($prima), 'la vecchia copia in chiaro non resta');
        }

        // Eliminando il documento sparisce anche la copia.
        $chiave = (string) $entity->getAnteprimaKey();
        $this->request('DELETE', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}", token: $this->token);
        $this->assertStatus(204);
        self::assertFalse($this->storage()->exists($chiave));
    }

    public function testDocumentsWithMacrosAreRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'macro');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->addFromString('word/vbaProject.bin', 'macro');
        $zip->close();
        $contenuto = (string) file_get_contents($path);
        unlink($path);

        $risposta = $this->upload($this->tid(), $this->token, $contenuto, 'lettera.docm', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->assertStatus(422);
        self::assertStringContainsString('macro', $risposta['detail']);
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array{0: string, 1: string} corpo e Content-Type */
    private function scarica(string $id, string $cosa): array
    {
        $this->client->request('GET', $this->uri($id, $cosa), server: $this->auth());
        $this->assertStatus(200);
        $r = $this->client->getResponse();

        return [(string) $r->getContent(), (string) $r->headers->get('Content-Type')];
    }

    /** @return list<class-string> messaggi in coda per il worker */
    private function inCoda(): array
    {
        /** @var InMemoryTransport $t */
        $t = static::getContainer()->get('messenger.transport.async');

        return array_map(static fn ($e) => $e->getMessage()::class, $t->getSent());
    }

    private function uri(string $id, string $cosa): string
    {
        return "/api/tenants/{$this->tid()}/documenti/$id/$cosa";
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }

    private function documento(string $id): Documento
    {
        $this->em()->clear();

        return $this->em()->find(Documento::class, Uuid::fromString($id));
    }

    private function storage(): DocumentStorage
    {
        return static::getContainer()->get(DocumentStorage::class);
    }

    private function convertitore(): FakeConvertitore
    {
        return static::getContainer()->get(FakeConvertitore::class);
    }

    private function anteprimaHandler(): AnteprimaDocumentoHandler
    {
        return static::getContainer()->get(AnteprimaDocumentoHandler::class);
    }

    private function ocrHandler(): OcrDocumentoHandler
    {
        return static::getContainer()->get(OcrDocumentoHandler::class);
    }
}
