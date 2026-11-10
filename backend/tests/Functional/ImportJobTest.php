<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\Entity\Documento;
use App\Documenti\Message\ClassificaDocumentoMessage;
use App\Documenti\Message\OcrDocumentoMessage;
use App\Documenti\MessageHandler\ClassificaDocumentoHandler;
use App\Documenti\MessageHandler\OcrDocumentoHandler;
use App\Import\Entity\ImportSource;
use App\Import\Enum\ImportProvider;
use App\Import\Enum\ImportStrategia;
use App\Import\ImportTokenVault;
use App\Import\Message\ImportJobMessage;
use App\Import\MessageHandler\ImportJobHandler;
use App\Import\OAuthTokens;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tests\Support\FakeClassifier;
use App\Tests\Support\FakeGoogleDrive;
use App\Tests\Support\TestGoogleOAuth;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Job di importazione (ImportRunner) e classificazione automatica dopo l'OCR. */
final class ImportJobTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
    }

    public function testFolderStrategyMapsFoldersToCategoriesAndSkipsDuplicatesBeforeDownload(): void
    {
        $this->driveTree();
        $source = $this->source(ImportStrategia::CARTELLE);

        $this->runJob($source);

        $esito = $this->reload($source)->getUltimoEsito();
        self::assertSame(['trovati' => 6, 'importati' => 5, 'duplicati' => 0, 'saltati' => 1, 'errori' => 0], $esito);
        self::assertSame('attivo', $this->reload($source)->getStato()->value);
        self::assertNotContains('nascosto', $this->drive()->downloads, 'Le sottocartelle oltre il primo livello si ignorano');

        $docs = $this->documentsByFileId();
        self::assertSame('Da categorizzare', $docs['radice-png']->getCategoria()->getNome());
        self::assertTrue($docs['radice-png']->isCifrato(), 'Da categorizzare è sensibile');
        self::assertSame('Bollette', $docs['bolletta']->getCategoria()->getNome(), 'Cartella "bollette" -> categoria di sistema');
        self::assertTrue($docs['bolletta']->getCategoria()->isSystem());
        self::assertFalse($docs['bolletta']->isCifrato());
        self::assertSame('Salute', $docs['referto']->getCategoria()->getNome());
        self::assertTrue($docs['referto']->isCifrato(), 'Cartella "SALUTE" -> Salute sensibile, non un duplicato non sensibile');
        $assicurazioni = $docs['polizza']->getCategoria();
        self::assertSame('Assicurazioni', $assicurazioni->getNome());
        self::assertFalse($assicurazioni->isSystem());
        self::assertFalse($assicurazioni->isSensibile());
        self::assertSame('application/pdf', $docs['gdoc']->getMimeType(), 'Google Documenti esportato in PDF');
        self::assertSame('Contratto.pdf', $docs['gdoc']->getNomeFileOriginale());
        self::assertSame($this->owner->getId()->toRfc4122(), $docs['gdoc']->getOwner()->getId()->toRfc4122());
        self::assertCount(5, $this->sent(OcrDocumentoMessage::class), 'OCR in coda per ogni documento importato');

        // Seconda sincronizzazione: tutto riconosciuto, nessun nuovo download.
        $downloads = \count($this->drive()->downloads);
        $this->runJob($source);
        self::assertSame(['trovati' => 6, 'importati' => 0, 'duplicati' => 5, 'saltati' => 1, 'errori' => 0], $this->reload($source)->getUltimoEsito());
        self::assertCount($downloads, $this->drive()->downloads);
        self::assertSame(5, $this->countDocs());

        // Un documento Google modificato è una nuova versione: si reimporta.
        $this->drive()->file('gdoc', 'Contratto', 'application/vnd.google-apps.document', 'f-assicurazioni', $this->fixture('ocr_fattura_scansione.pdf')."\n%v2", '2026-03-01T09:00:00.000Z');
        $this->runJob($source);
        self::assertSame(1, $this->reload($source)->getUltimoEsito()['importati']);
    }

    public function testFileAlreadyUploadedManuallyIsNotDownloaded(): void
    {
        $png = $this->fixture('ocr_fattura.png');
        $this->upload((string) $this->tenant->getId(), $this->login($this->owner), $png, 'a mano.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->assertStatus(201);
        $this->drive()->folder('radice', 'Documenti')->file('stesso', 'scansione.png', 'image/png', 'radice', $png);

        $this->runJob($this->source(ImportStrategia::CARTELLE));

        self::assertSame([], $this->drive()->downloads);
        self::assertSame(1, $this->countDocs());
    }

    public function testAiStrategyPutsEverythingInDaCategorizzareEncrypted(): void
    {
        $this->driveTree();
        $source = $this->source(ImportStrategia::AI_CONTENUTO);

        $this->runJob($source);

        foreach ($this->documentsByFileId() as $doc) {
            self::assertTrue($doc->getCategoria()->isDaCategorizzare());
            self::assertTrue($doc->isCifrato());
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM categoria WHERE tenant_id IS NOT NULL'), 'Nessuna categoria creata');
    }

    public function testRevokedAccessMarksSourceInError(): void
    {
        $this->driveTree();
        $this->drive()->revoked = true;
        $source = $this->source(ImportStrategia::CARTELLE);

        $this->runJob($source);

        $source = $this->reload($source);
        self::assertSame('errore', $source->getStato()->value);
        self::assertStringContainsString('revocato', (string) $source->getUltimoErrore());
        self::assertNull($source->getSincronizzazioneRichiestaIl());
        self::assertCount(1, $this->auditRows('import.sync_failed', 'failure'));
    }

    public function testExpiredAccessTokenIsRefreshedAndStoredEncrypted(): void
    {
        $this->driveTree();
        $source = $this->source(ImportStrategia::CARTELLE, new \DateTimeImmutable('-1 minute'));

        $this->runJob($source);

        self::assertSame(1, static::getContainer()->get(TestGoogleOAuth::class)->refreshCount);
        self::assertSame(['access-token-2'], array_values(array_unique($this->drive()->tokens)));
        self::assertSame('access-token-2', static::getContainer()->get(ImportTokenVault::class)->accessToken($this->reload($source)));
    }

    public function testCreatorWithoutPermissionAnymoreStopsTheImport(): void
    {
        $this->driveTree();
        $source = $this->source(ImportStrategia::CARTELLE);
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $this->owner->getId()]);
        $membership->changeRole($this->systemRole(Role::MEMBER));
        $this->em()->flush();

        $this->runJob($source);

        self::assertSame('errore', $this->reload($source)->getStato()->value);
        self::assertSame(0, $this->countDocs());
    }

    // ---- Caratterizzazione (fase 0): rami non coperti ------------------------

    public function testDisconnectedSourceDoesNothingAndClearsTheRequest(): void
    {
        $this->driveTree();
        $source = $this->source(ImportStrategia::CARTELLE);
        $this->reload($source)->disconnect('scollegata a mano');
        $this->em()->flush();

        $this->runJob($source);

        self::assertSame(0, $this->countDocs());
        self::assertSame([], $this->drive()->tokens, 'nessuna chiamata a Drive');
        self::assertNull($this->reload($source)->getSincronizzazioneRichiestaIl());
    }

    public function testAccessRevokedDuringDownloadStopsTheImport(): void
    {
        $this->driveTree();
        $this->drive()->revocaDownload = true;
        $source = $this->source(ImportStrategia::CARTELLE);

        $this->runJob($source);

        $source = $this->reload($source);
        self::assertSame('errore', $source->getStato()->value);
        self::assertNotEmpty($source->getUltimoErrore());
        self::assertSame(0, $this->countDocs());
        self::assertSame(1, $source->getUltimoEsito()['trovati'] ?? null, 'si ferma al primo file da scaricare');
        self::assertCount(1, $this->auditRows('import.sync_failed', 'failure'));
    }

    public function testUnreadableFileIsCountedAsErrorAndTheOthersAreImported(): void
    {
        $this->driveTree();
        $this->drive()->file('rotto', 'rotto.png', 'image/png', 'radice', "\x00\x01\x02\x03 questo non è un png");
        $source = $this->source(ImportStrategia::CARTELLE);

        $this->runJob($source);

        $source = $this->reload($source);
        self::assertSame('attivo', $source->getStato()->value, 'un file rovinato non blocca la sincronizzazione');
        self::assertSame(1, $source->getUltimoEsito()['errori']);
        self::assertGreaterThan(0, $source->getUltimoEsito()['importati']);
        self::assertArrayNotHasKey('rotto', $this->documentsByFileId());
    }

    public function testDriveErrorWhileListingMarksTheSourceInError(): void
    {
        $this->driveTree();
        $this->drive()->erroreLista = 500;
        $source = $this->source(ImportStrategia::CARTELLE);

        $this->runJob($source);

        $source = $this->reload($source);
        self::assertSame('errore', $source->getStato()->value);
        self::assertNotEmpty($source->getUltimoErrore());
        self::assertSame(0, $this->countDocs());
    }

    public function testAiClassificationAfterOcrMovesDocumentButNeverDecryptsIt(): void
    {
        $this->drive()->folder('radice', 'Documenti')->file('fattura', 'scan001.png', 'image/png', 'radice', $this->fixture('ocr_fattura.png'));
        $this->runJob($this->source(ImportStrategia::AI_CONTENUTO));
        $doc = $this->documentsByFileId()['fattura'];

        $this->classifier()->risposta = 'Bollette';
        $this->ocr($doc);

        $sent = $this->sent(ClassificaDocumentoMessage::class);
        self::assertCount(1, $sent, 'Dopo l\'OCR la classificazione va in coda');
        static::getContainer()->get(ClassificaDocumentoHandler::class)($sent[0]);

        $call = $this->classifier()->calls[0];
        self::assertSame('scan001.png', $call['nomeFile']);
        self::assertStringContainsString('FATTURA', $call['testo']);
        self::assertContains('Bollette', $call['categorie']);
        self::assertNotContains('Da categorizzare', $call['categorie']);

        $doc = $this->documentsByFileId()['fattura'];
        self::assertSame('Bollette', $doc->getCategoria()->getNome());
        self::assertTrue($doc->isCifrato(), 'Verso una categoria non sensibile il documento resta cifrato');
        self::assertCount(1, $this->auditRows('documento.classificato', 'success'));
    }

    public function testLowConfidenceAnswerLeavesDocumentWhereItIs(): void
    {
        $this->drive()->folder('radice', 'Documenti')->file('fattura', 'scan001.png', 'image/png', 'radice', $this->fixture('ocr_fattura.png'));
        $this->runJob($this->source(ImportStrategia::AI_CONTENUTO));
        $doc = $this->documentsByFileId()['fattura'];

        $this->classifier()->risposta = 'Categoria inventata';
        $this->ocr($doc);
        static::getContainer()->get(ClassificaDocumentoHandler::class)($this->sent(ClassificaDocumentoMessage::class)[0]);

        self::assertTrue($this->documentsByFileId()['fattura']->getCategoria()->isDaCategorizzare());
        self::assertCount(1, $this->auditRows('documento.classificazione_incerta', 'success'));
    }

    public function testFolderStrategyDoesNotTriggerClassification(): void
    {
        $this->drive()->folder('radice', 'Documenti')->file('fattura', 'scan001.png', 'image/png', 'radice', $this->fixture('ocr_fattura.png'));
        $this->runJob($this->source(ImportStrategia::CARTELLE));

        $this->ocr($this->documentsByFileId()['fattura']);

        self::assertSame([], $this->sent(ClassificaDocumentoMessage::class));
    }

    /**
     * radice/
     *   scansione.png               -> Da categorizzare
     *   video.mp4                   -> tipo non supportato (saltato)
     *   bollette/bolletta.png       -> Bollette (sistema)
     *   SALUTE/referto.png          -> Salute (sistema, sensibile)
     *   Assicurazioni/polizza.png   -> nuova categoria del tenant
     *   Assicurazioni/Contratto     -> Google Documenti, esportato in PDF
     *   Assicurazioni/vecchi/x.png  -> secondo livello: ignorato
     */
    private function driveTree(): void
    {
        $png = $this->fixture('ocr_fattura.png');
        $this->drive()
            ->folder('radice', 'CartellaViva')
            ->file('radice-png', 'scansione.png', 'image/png', 'radice', $png."\x00root")
            ->file('video', 'video.mp4', 'video/mp4', 'radice', 'ciao')
            ->folder('f-bollette', 'bollette', 'radice')
            ->file('bolletta', 'bolletta.png', 'image/png', 'f-bollette', $png."\x00b")
            ->folder('f-salute', 'SALUTE', 'radice')
            ->file('referto', 'referto.png', 'image/png', 'f-salute', $png."\x00s")
            ->folder('f-assicurazioni', 'Assicurazioni', 'radice')
            ->file('polizza', 'polizza.png', 'image/png', 'f-assicurazioni', $png."\x00p")
            ->file('gdoc', 'Contratto', 'application/vnd.google-apps.document', 'f-assicurazioni', $this->fixture('ocr_fattura_scansione.pdf'))
            ->folder('f-vecchi', 'vecchi', 'f-assicurazioni')
            ->file('nascosto', 'x.png', 'image/png', 'f-vecchi', $png."\x00n");
    }

    private function source(ImportStrategia $strategia, ?\DateTimeImmutable $scadenza = null): ImportSource
    {
        $source = new ImportSource($this->fresh($this->tenant), $this->fresh($this->owner), ImportProvider::GOOGLE_DRIVE, 'radice', $strategia);
        $this->em()->persist($source);
        static::getContainer()->get(ImportTokenVault::class)->store($source, new OAuthTokens('access-token-1', 'refresh-token-segreto', $scadenza ?? new \DateTimeImmutable('+1 hour')));
        $source->markConnected('famiglia@gmail.com');
        $this->em()->flush();

        return $source;
    }

    private function runJob(ImportSource $source): void
    {
        $this->reload($source)->requestSync();
        $this->em()->flush();
        static::getContainer()->get(ImportJobHandler::class)(new ImportJobMessage((string) $source->getId()));
    }

    private function ocr(Documento $doc): void
    {
        static::getContainer()->get(OcrDocumentoHandler::class)(new OcrDocumentoMessage((string) $doc->getId()));
    }

    private function reload(ImportSource $source): ImportSource
    {
        $this->em()->clear();

        return $this->em()->find(ImportSource::class, $source->getId());
    }

    /** @return array<string, Documento> */
    private function documentsByFileId(): array
    {
        $this->em()->clear();
        $docs = [];
        foreach ($this->em()->getRepository(Documento::class)->findAll() as $doc) {
            $docs[(string) $doc->getSorgenteFileId()] = $doc;
        }

        return $docs;
    }

    private function countDocs(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM documento');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function sent(string $class): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');

        return array_values(array_map(static fn ($e) => $e->getMessage(), array_filter($transport->getSent(), static fn ($e) => $e->getMessage() instanceof $class)));
    }

    private function drive(): FakeGoogleDrive
    {
        return static::getContainer()->get(FakeGoogleDrive::class);
    }

    private function classifier(): FakeClassifier
    {
        return static::getContainer()->get(FakeClassifier::class);
    }
}
