<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\Entity\Documento;
use App\Documenti\Message\OcrDocumentoMessage;
use App\Documenti\MessageHandler\OcrDocumentoHandler;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class DocumentoTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
        $this->member = $this->createUser('membro@example.com');
        $this->addMember($this->member, $this->tenant, Role::MEMBER);
    }

    public function testNonSensitiveUploadIsStoredInClearQueuedForOcrAndDuplicatesAreFlagged(): void
    {
        $png = $this->fixture('ocr_fattura.png');
        $token = $this->login($this->member);

        $doc = $this->upload($this->tid(), $token, $png, 'bolletta luce.png', ['categoriaId' => (string) $this->categoria('bollette')->getId()]);

        $this->assertStatus(201);
        self::assertFalse($doc['cifrato']);
        self::assertSame('bolletta luce', $doc['titolo']);
        self::assertSame('image/png', $doc['mimeType']);
        self::assertSame('in_coda', $doc['ocrStatus']);
        self::assertSame([], $doc['possibiliDuplicati']);
        self::assertSame("/api/tenants/{$this->tid()}/documenti/{$doc['id']}/file", $doc['downloadUrl']);
        self::assertArrayNotHasKey('storageKey', $doc, 'Lo storage non va mai esposto');

        $entity = $this->document($doc['id']);
        self::assertSame($png, $this->storage()->read($entity->getStorageKey()));
        self::assertSame(hash('sha256', $png), $entity->getHashSha256());
        self::assertCount(1, $this->ocrQueue()->getSent());

        // Stesso contenuto: segnalato, non bloccato.
        $again = $this->upload($this->tid(), $token, $png, 'copia.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->assertStatus(201);
        self::assertSame([$doc['id']], array_column($again['possibiliDuplicati'], 'id'));
    }

    public function testSensitiveUploadIsEncryptedAtRestAndDecryptedOnlyOnAuthorizedDownload(): void
    {
        $png = $this->fixture('ocr_fattura.png');
        $token = $this->login($this->member);

        $doc = $this->upload($this->tid(), $token, $png, 'referto.png', ['categoriaId' => (string) $this->categoria('salute')->getId()]);
        $this->assertStatus(201);
        self::assertTrue($doc['cifrato']);

        $entity = $this->document($doc['id']);
        $stored = $this->storage()->read($entity->getStorageKey());
        self::assertNotSame($png, $stored);
        self::assertStringStartsWith('FV1', $stored);
        self::assertStringNotContainsString("\x89PNG", $stored);
        self::assertNotNull($entity->getEncryptedDek());
        self::assertSame(Documento::HASH_HMAC, $entity->getHashTipo());
        self::assertNotSame(hash('sha256', $png), $entity->getHashSha256(), 'Per i documenti cifrati niente SHA-256 in chiaro');

        $this->client->request('GET', $doc['downloadUrl'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($this->owner)]);
        $this->assertStatus(200);
        self::assertSame($png, $this->client->getResponse()->getContent());
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        self::assertCount(1, $this->auditRows('documento.created', 'success'));
        self::assertCount(1, $this->auditRows('documento.download', 'success'));
    }

    public function testViewerCannotUploadAndNothingIsStored(): void
    {
        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);

        $this->upload($this->tid(), $this->login($viewer), $this->fixture('ocr_fattura.png'), 'x.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);

        $this->assertStatus(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM documento'));
        self::assertSame('documento:create', $this->auditRows('permission.check', 'denied')[0]['permission']);
    }

    public function testOtherTenantCannotListReadOrDownload(): void
    {
        $doc = $this->upload($this->tid(), $this->login($this->member), $this->fixture('ocr_fattura.png'), 'x.png', ['categoriaId' => (string) $this->categoria('salute')->getId()]);
        $altro = $this->createUser('luca@example.com');
        $this->addMember($altro, $this->createTenant('Famiglia Bianchi'), Role::OWNER);
        $token = $this->login($altro);

        $this->request('GET', "/api/tenants/{$this->tid()}/documenti", token: $token);
        $this->assertStatus(403);
        $this->request('GET', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}", token: $token);
        $this->assertStatus(403);
        $this->client->request('GET', $doc['downloadUrl'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $this->assertStatus(403);
    }

    public function testOwnScopeMemberEditsOnlyOwnDocumentsOwnerEditsAll(): void
    {
        $ownerDoc = $this->upload($this->tid(), $this->login($this->owner), $this->fixture('ocr_fattura.png'), 'owner.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $memberToken = $this->login($this->member);
        $memberDoc = $this->upload($this->tid(), $memberToken, $this->fixture('ocr_fattura_scansione.pdf'), 'membro.pdf', ['categoriaId' => (string) $this->categoria('casa')->getId()]);

        $this->patch("/api/tenants/{$this->tid()}/documenti/{$ownerDoc['id']}", ['titolo' => 'Hack'], $memberToken);
        $this->assertStatus(403);
        $this->request('DELETE', "/api/tenants/{$this->tid()}/documenti/{$ownerDoc['id']}", token: $memberToken);
        $this->assertStatus(403);

        $updated = $this->patch("/api/tenants/{$this->tid()}/documenti/{$memberDoc['id']}", ['titolo' => 'Rinominato'], $memberToken);
        $this->assertStatus(200);
        self::assertSame('Rinominato', $updated['titolo']);

        $this->request('DELETE', "/api/tenants/{$this->tid()}/documenti/{$memberDoc['id']}", token: $this->login($this->owner));
        $this->assertStatus(204);
    }

    public function testOcrOnSensitiveDocumentStoresOnlyEncryptedText(): void
    {
        $doc = $this->upload($this->tid(), $this->login($this->member), $this->fixture('ocr_fattura.png'), 'referto.png', ['categoriaId' => (string) $this->categoria('salute')->getId()]);

        $this->handler()(new OcrDocumentoMessage($doc['id']));

        $row = $this->em()->getConnection()->fetchAssociative('SELECT ocr_status, ocr_text, ocr_text_cifrato FROM documento WHERE id = ?', [$doc['id']]);
        self::assertSame('completato', $row['ocr_status']);
        self::assertNull($row['ocr_text'], 'Il testo di un documento sensibile non va salvato in chiaro');
        self::assertStringNotContainsString('FATTURA', base64_decode($row['ocr_text_cifrato']));

        $detail = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}", token: $this->login($this->owner));
        self::assertStringContainsString('FATTURA', $detail['ocrText']);
        self::assertCount(1, $this->auditRows('documento.read', 'success'));
    }

    public function testOcrOnScannedPdfAndFulltextSearch(): void
    {
        $token = $this->login($this->member);
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura_scansione.pdf'), 'scansione.pdf', ['categoriaId' => (string) $this->categoria('casa')->getId()]);

        $this->handler()(new OcrDocumentoMessage($doc['id']));

        self::assertStringContainsString('FATTURA', (string) $this->document($doc['id'])->getOcrText());
        $found = $this->request('GET', "/api/tenants/{$this->tid()}/documenti?q=fattura", token: $token);
        self::assertSame([$doc['id']], array_column($found, 'id'));
    }

    public function testOcrFailureIsRecordedAndCanBeRetried(): void
    {
        // PNG vero ma troncato: il tipo è riconosciuto, l'immagine non è leggibile.
        $broken = substr($this->fixture('ocr_fattura.png'), 0, 120);
        $token = $this->login($this->member);
        $doc = $this->upload($this->tid(), $token, $broken, 'rotto.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->assertStatus(201);

        $this->handler()(new OcrDocumentoMessage($doc['id']));

        $row = $this->em()->getConnection()->fetchAssociative('SELECT ocr_status, ocr_errore FROM documento WHERE id = ?', [$doc['id']]);
        self::assertSame('fallito', $row['ocr_status']);
        self::assertNotEmpty($row['ocr_errore']);
        self::assertCount(1, $this->auditRows('documento.ocr_failed', 'failure'));

        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}/ocr", token: $token);
        $this->assertStatus(202);
    }

    /** L'estensione del nome segue il contenuto, non quella scelta da chi carica. */
    public function testFileNameExtensionFollowsTheContent(): void
    {
        $token = $this->login($this->member);
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'bolletta.html', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->assertStatus(201);
        self::assertSame('bolletta.png', $this->document($doc['id'])->getNomeFileOriginale());
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png').'x', 'foto.PNG', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        self::assertSame('foto.PNG', $this->document($doc['id'])->getNomeFileOriginale(), 'un\'estensione già giusta resta com\'è');
    }

    public function testUnsupportedFileTypeIsRejected(): void
    {
        $this->upload($this->tid(), $this->login($this->member), "#!/bin/sh\necho ciao\n", 'script.pdf', ['categoriaId' => (string) $this->categoria('casa')->getId()]);

        $this->assertStatus(422);
    }

    public function testMovingToSensitiveCategoryEncryptsTheDocument(): void
    {
        $png = $this->fixture('ocr_fattura.png');
        $token = $this->login($this->member);
        $doc = $this->upload($this->tid(), $token, $png, 'x.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $oldKey = $this->document($doc['id'])->getStorageKey();

        // Tutto o niente: con un dato non valido il documento non viene né spostato né cifrato.
        $this->patch("/api/tenants/{$this->tid()}/documenti/{$doc['id']}", ['categoriaId' => (string) $this->categoria('farmaci')->getId(), 'membriIds' => ['00000000-0000-7000-8000-000000000000']], $token);
        $this->assertStatus(422);
        self::assertSame([false, 'casa'], [$this->document($doc['id'])->isCifrato(), $this->document($doc['id'])->getCategoria()->getSlug()]);

        $updated =$this->patch("/api/tenants/{$this->tid()}/documenti/{$doc['id']}", ['categoriaId' => (string) $this->categoria('farmaci')->getId(), 'titolo' => 'Ricetta', 'dataDocumento' => '2026-03-01'], $token);

        $this->assertStatus(200);
        self::assertTrue($updated['cifrato']);
        self::assertSame(['Ricetta', '2026-03-01'], [$updated['titolo'], $updated['dataDocumento']], 'le altre modifiche della stessa richiesta restano');
        self::assertSame('Ricetta', $this->document($doc['id'])->getTitolo());
        $entity = $this->document($doc['id']);
        self::assertFalse($this->storage()->fileExists($oldKey), 'La copia in chiaro va eliminata');
        self::assertStringStartsWith('FV1', $this->storage()->read($entity->getStorageKey()));

        $this->client->request('GET', $doc['downloadUrl'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame($png, $this->client->getResponse()->getContent());
    }

    /**
     * Il worker dell'OCR ha già caricato il documento (in chiaro) quando
     * l'utente lo sposta in una categoria sensibile; finito l'OCR, il testo non
     * deve finire in chiaro accanto a un documento ormai cifrato.
     */
    public function testOcrFinishingAfterTheDocumentWasEncryptedStoresOnlyEncryptedText(): void
    {
        $token = $this->login($this->member);
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'x.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $copiaDelWorker = $this->document($doc['id']);
        $statoLetto = $this->em()->getUnitOfWork()->getOriginalEntityData($copiaDelWorker);
        $contenutoLetto = $this->storage()->read($copiaDelWorker->getStorageKey());

        $this->patch("/api/tenants/{$this->tid()}/documenti/{$doc['id']}", ['categoriaId' => (string) $this->categoria('farmaci')->getId()], $token);
        $this->assertStatus(200);
        self::assertFalse($copiaDelWorker->isCifrato(), 'la copia del worker è quella letta prima del cambio');
        // Il worker ha già il contenuto in memoria e sta facendo l'OCR (qui: lo rilegge da dove l'aveva letto).
        $this->storage()->write($copiaDelWorker->getStorageKey(), $contenutoLetto);
        $this->em()->clear();
        $this->em()->getUnitOfWork()->registerManaged($copiaDelWorker, ['id' => $copiaDelWorker->getId()], $statoLetto);

        $this->handler()(new OcrDocumentoMessage($doc['id']));

        $row = $this->em()->getConnection()->fetchAssociative('SELECT cifrato, ocr_status, ocr_text, ocr_text_cifrato FROM documento WHERE id = ?', [$doc['id']]);
        self::assertSame([true, 'completato'], [$row['cifrato'], $row['ocr_status']]);
        self::assertNull($row['ocr_text'], 'il testo di un documento cifrato non va salvato in chiaro');
        self::assertStringContainsString('FATTURA', $this->request('GET', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}", token: $token)['ocrText']);
    }

    public function testListingWithSensitiveDocumentsIsAudited(): void
    {
        $token = $this->login($this->member);
        $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'a.png', ['categoriaId' => (string) $this->categoria('salute')->getId()]);

        $list = $this->request('GET', "/api/tenants/{$this->tid()}/documenti", token: $token);

        self::assertCount(1, $list);
        self::assertArrayNotHasKey('ocrText', $list[0], 'Il testo OCR solo nel dettaglio');
        self::assertCount(1, $this->auditRows('documento.list', 'success'));
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }

    private function document(string $id): Documento
    {
        $this->em()->clear();

        return $this->em()->find(Documento::class, Uuid::fromString($id));
    }

    private function storage(): FilesystemOperator
    {
        return static::getContainer()->get('documents.storage');
    }

    private function ocrQueue(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    private function handler(): OcrDocumentoHandler
    {
        return static::getContainer()->get(OcrDocumentoHandler::class);
    }
}
