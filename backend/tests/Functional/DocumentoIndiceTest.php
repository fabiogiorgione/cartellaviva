<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Documenti\Indice\AiEstrattoreDati;
use App\Documenti\Indice\IndiceDocumenti;
use App\Documenti\Message\IndicizzaDocumentoMessage;
use App\Documenti\MessageHandler\IndicizzaDocumentoHandler;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tests\Support\FakeEmbedder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Indice dei documenti: dati estratti, tag e riferimenti, ricerca per
 * significato e documenti simili. Mai per i documenti cifrati.
 */
final class DocumentoIndiceTest extends ApiTestCase
{
    private const BOLLETTA = "ENEL ENERGIA S.p.A.\nFattura n. 004512378 del 15/03/2025\nIntestatario: MARIO ROSSI\nFornitura energia elettrica luce POD IT001E12345678\nTotale da pagare: 87,40 euro";
    private const POLIZZA = "UnipolSai Assicurazioni\nPolizza RC Auto n. 1234567890 del 12/05/2025\nVeicolo Fiat Panda targa AB123CD\nPremio annuo 540,00 euro";
    private const LIBRETTO = "Carta di circolazione\nMotorizzazione civile\nTarga AB123CD Fiat Panda benzina\nRevisione 10/02/2025";
    private const PAGELLA = "Scuola Primaria Demo\nPagella anno scolastico 2024/2025\nAlunno Leo Rossi classe 3B\nItaliano ottimo matematica distinto";

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
        $this->embedder()->enabled = true;
        $this->embedder()->guasto = false;
        $this->embedder()->modelloMancante = false;
    }

    public function testFoundDateFillsOnlyAnEmptyDocumentDate(): void
    {
        $vuota = $this->documento('casa', 'a.png', 'Fattura del 15/03/2025');
        $this->indicizza($vuota, ['data' => '2025-03-15']);
        self::assertSame('2025-03-15', $this->entity($vuota)->getDataDocumento()?->format('Y-m-d'));

        $aMano = $this->documento('casa', 'b.png', 'Fattura del 15/03/2025');
        $entity = $this->entity($aMano);
        $entity->setDataDocumento(new \DateTimeImmutable('2020-01-02'));
        $this->em()->flush();
        $this->indicizza($aMano, ['data' => '2025-03-15']);
        self::assertSame('2020-01-02', $this->entity($aMano)->getDataDocumento()?->format('Y-m-d'), 'una data inserita a mano non si tocca');

        // Una data futura è una scadenza letta per sbaglio: non è la data del documento.
        $futura = $this->documento('casa', 'c.png', 'Valido fino al 01/01/2099');
        $this->indicizza($futura, ['data' => '2099-01-01']);
        self::assertNull($this->entity($futura)->getDataDocumento());
    }

    public function testPlainDocumentIsIndexedAfterOcrAndEncryptedNeverIs(): void
    {
        $bolletta = $this->documento('bollette', 'bolletta.png', self::BOLLETTA);
        $referto = $this->documento('salute', 'referto.png', 'Referto emocromo glicemia');

        // Dopo l'OCR: in coda solo il documento in chiaro.
        $this->queue()->reset();
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->dispatch(new DocumentoOcrCompletatoEvent($this->entity($bolletta)));
        $dispatcher->dispatch(new DocumentoOcrCompletatoEvent($this->entity($referto)));
        $inCoda = array_map(static fn ($e) => $e->getMessage(), array_filter($this->queue()->getSent(), static fn ($e) => $e->getMessage() instanceof IndicizzaDocumentoMessage));
        self::assertSame([$bolletta], array_values(array_map(static fn (IndicizzaDocumentoMessage $m) => $m->documentoId, $inCoda)));

        $this->indicizza($bolletta, [
            'emittente' => 'Enel Energia S.p.A.', 'data' => '2025-03-15', 'importo' => '87,40', 'numero' => '004512378',
            'intestatario' => 'Mario Rossi', 'riferimenti' => ['IT001E12345678'], 'tag' => ['Luce', 'bolletta'],
        ]);
        $dettaglio = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$bolletta", token: $this->token);
        self::assertSame(['emittente' => 'Enel Energia S.p.A.', 'data' => '2025-03-15', 'importo' => '87.40', 'numero' => '004512378', 'intestatario' => 'Mario Rossi'], $dettaglio['indice']['dati']);
        self::assertSame(['luce', 'bolletta'], $dettaglio['indice']['tag']);
        self::assertSame(['IT001E12345678'], $dettaglio['indice']['riferimenti']);
        self::assertGreaterThan(0, $this->parti($bolletta));
        $contesto = json_decode((string) $this->auditRows('documento.indicizzato')[0]['context'], true);
        self::assertStringNotContainsString('87', json_encode($contesto), 'nell\'audit nessun dato estratto');

        // Anche invocato direttamente, il documento cifrato non viene indicizzato né inviato a Ollama.
        $chiamate = \count($this->embedder()->calls);
        $this->indicizza($referto, ['emittente' => 'Ospedale']);
        self::assertSame(0, $this->parti($referto));
        self::assertNull($this->request('GET', "/api/tenants/{$this->tid()}/documenti/$referto", token: $this->token)['indice']);
        self::assertCount($chiamate, $this->embedder()->calls);
    }

    public function testMissingModelsSkipTheirPartWithoutRetrying(): void
    {
        // Modello di embedding non scaricato: i dati estratti si salvano, i vettori no.
        $id = $this->documento('bollette', 'b.png', self::BOLLETTA);
        $this->embedder()->modelloMancante = true;
        $this->indicizza($id, ['tag' => ['luce']]);
        self::assertSame(0, $this->parti($id));
        self::assertSame(['luce'], $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$id", token: $this->token)['indice']['tag']);

        // Nessun modello scaricato: niente indice e nessuna eccezione (nessun nuovo tentativo).
        $id2 = $this->documento('bollette', 'c.png', self::BOLLETTA);
        $this->indicizza($id2, null);
        self::assertNull($this->request('GET', "/api/tenants/{$this->tid()}/documenti/$id2", token: $this->token)['indice']);

        // Dopo il pull, il comando le rimette in coda tutte e due (vettori mancanti).
        $this->embedder()->modelloMancante = false;
        $this->queue()->reset();
        (new CommandTester((new Application(static::$kernel))->find('app:documenti:indicizza')))->execute([]);
        self::assertSame([$id, $id2], array_map(static fn ($e) => $e->getMessage()->documentoId, $this->queue()->getSent()));
    }

    public function testExtractedValuesNotInTheTextAreDropped(): void
    {
        $id = $this->documento('bollette', 'b.png', self::BOLLETTA);
        $this->indicizza($id, [
            'emittente' => 'Acea', 'data' => '2031-01-01', 'importo' => '99,00', 'numero' => '777777',
            'intestatario' => 'Giulia Bianchi', 'riferimenti' => ['ZZ999ZZ', 'RSSMRA80A01F205X'], 'tag' => [],
        ]);
        $indice = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$id", token: $this->token)['indice'];
        self::assertSame(['emittente' => null, 'data' => null, 'importo' => null, 'numero' => null, 'intestatario' => null], $indice['dati']);
        self::assertSame([], $indice['riferimenti']);
    }

    public function testEncryptingADocumentRemovesItsIndex(): void
    {
        $id = $this->documento('casa', 'contratto.png', self::BOLLETTA);
        $this->indicizza($id, ['tag' => ['luce']]);
        self::assertGreaterThan(0, $this->parti($id));

        // Spostato in una categoria sensibile: cifrato, e l'indice sparisce.
        $this->patch("/api/tenants/{$this->tid()}/documenti/$id", ['categoriaId' => (string) $this->categoria('salute')->getId()], $this->token);
        $this->assertStatus(200);
        self::assertTrue($this->entity($id)->isCifrato());
        self::assertSame(0, $this->parti($id));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM documento_indice WHERE documento_id = :id', ['id' => $id]));
        self::assertNull($this->request('GET', "/api/tenants/{$this->tid()}/documenti/$id", token: $this->token)['indice']);
        self::assertSame([], $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$id/simili", token: $this->token));
    }

    public function testSemanticSearchFindsWithoutTheExactWordsAndKeepsFiltersAndTenants(): void
    {
        $bolletta = $this->documento('bollette', 'scansione1.png', self::BOLLETTA);
        $pagella = $this->documento('scuola', 'scansione2.png', self::PAGELLA);
        $this->indicizza($bolletta, []);
        $this->indicizza($pagella, []);
        // Cifrato: non ha vettori, ma resta trovabile per titolo.
        $cifrato = $this->documento('salute', 'fattura della luce 2019.png', 'testo');

        // Un'altra famiglia con un documento identico: mai nei risultati.
        $altra = $this->createTenant('Famiglia Bianchi');
        $luca = $this->createUser('luca@example.com');
        $this->addMember($luca, $altra, Role::OWNER);
        $this->documento('bollette', 'altra.png', self::BOLLETTA, $altra, $this->login($luca));
        $this->indicizza($this->ultimo($altra), []);

        $url = "/api/tenants/{$this->tid()}/documenti?modo=significato&q=".rawurlencode('fattura della luce');
        // Per parole: "fattura della luce" compare solo nel titolo del documento cifrato.
        self::assertSame([$cifrato], array_column($this->request('GET', "/api/tenants/{$this->tid()}/documenti?q=".rawurlencode('fattura della luce'), token: $this->token), 'id'));
        $risultati = $this->request('GET', $url, token: $this->token);
        $this->assertStatus(200);
        self::assertSame($bolletta, $risultati[0]['id'], 'prima il documento più vicino per significato');
        self::assertNotContains($pagella, array_column($risultati, 'id'), 'non pertinente: escluso dalla soglia');
        self::assertSame('2', $this->client->getResponse()->headers->get('X-Total-Count'));
        self::assertSame([$bolletta, $cifrato], array_column($risultati, 'id'));

        // I filtri della lista valgono anche qui.
        self::assertSame([], $this->request('GET', $url.'&categoriaId='.$this->categoria('scuola')->getId(), token: $this->token));
        // A pagine: la seconda pagina da 1 contiene il secondo risultato.
        self::assertSame([$cifrato], array_column($this->request('GET', $url.'&itemsPerPage=1&page=2', token: $this->token), 'id'));

        // Ollama non risponde: si ripiega sulla ricerca per parole, senza errori.
        $this->embedder()->guasto = true;
        $this->request('GET', $url, token: $this->token);
        $this->assertStatus(200);
    }

    public function testSimilarDocumentsShareAReferenceOrTheContent(): void
    {
        $polizza = $this->documento('veicoli', 'polizza.png', self::POLIZZA);
        $libretto = $this->documento('veicoli', 'libretto.png', self::LIBRETTO);
        $bolletta = $this->documento('bollette', 'bolletta.png', self::BOLLETTA);
        $bolletta2 = $this->documento('bollette', 'bolletta2.png', self::BOLLETTA."\nSecondo bimestre");
        $this->indicizza($polizza, ['riferimenti' => ['AB 123 CD', '1234567890']]);
        $this->indicizza($libretto, ['riferimenti' => ['AB123CD']]);
        $this->indicizza($bolletta, ['riferimenti' => ['IT001E12345678']]);
        $this->indicizza($bolletta2, []);

        $simili = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$polizza/simili", token: $this->token);
        $this->assertStatus(200);
        self::assertSame([['id' => $libretto, 'motivo' => 'riferimento', 'riferimento' => 'AB123CD']], array_map(static fn ($s) => array_intersect_key($s, ['id' => 1, 'motivo' => 1, 'riferimento' => 1]), $simili));

        $simili = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$bolletta2/simili", token: $this->token);
        self::assertSame([[$bolletta, 'contenuto']], array_map(static fn ($s) => [$s['id'], $s['motivo']], $simili));

        // Un'altra famiglia non vede nulla.
        $altra = $this->createTenant('Altra');
        $estraneo = $this->createUser('estraneo@example.com');
        $this->addMember($estraneo, $altra, Role::OWNER);
        $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$polizza/simili", token: $this->login($estraneo));
        $this->assertStatus(403);
    }

    public function testReanalysisNeedsWritePermissionAndAPlainDocument(): void
    {
        $id = $this->documento('bollette', 'b.png', self::BOLLETTA);
        $cifrato = $this->documento('salute', 'r.png', 'referto');
        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);

        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/$id/indice", token: $this->login($viewer));
        $this->assertStatus(403);
        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/$cifrato/indice", token: $this->token);
        $this->assertStatus(409);

        $this->queue()->reset();
        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/$id/indice", token: $this->token);
        $this->assertStatus(202);
        self::assertInstanceOf(IndicizzaDocumentoMessage::class, $this->queue()->getSent()[0]->getMessage());
    }

    public function testCommandQueuesOnlyPlainDocumentsNotYetIndexed(): void
    {
        $fatto = $this->documento('bollette', 'a.png', self::BOLLETTA);
        $mancante = $this->documento('scuola', 'b.png', self::PAGELLA);
        $this->documento('salute', 'c.png', 'referto');
        $this->indicizza($fatto, []);

        $this->queue()->reset();
        $tester = new CommandTester((new Application(static::$kernel))->find('app:documenti:indicizza'));
        self::assertSame(0, $tester->execute([]));
        self::assertSame([$mancante], array_map(static fn ($e) => $e->getMessage()->documentoId, $this->queue()->getSent()));

        $this->queue()->reset();
        $tester->execute(['--tutti' => true]);
        self::assertSame([$fatto, $mancante], array_map(static fn ($e) => $e->getMessage()->documentoId, $this->queue()->getSent()));
    }

    public function testProgressCountsDocumentsByAnalysisState(): void
    {
        $fatto = $this->documento('bollette', 'a.png', self::BOLLETTA);
        $this->documento('scuola', 'b.png', self::PAGELLA);
        $this->documento('salute', 'c.png', 'referto');
        $vuoto = $this->documento('casa', 'd.png', '   ');
        $this->indicizza($fatto, []);
        $this->indicizza($vuoto, []);
        // Testo ancora da leggere.
        $this->upload($this->tid(), $this->token, $this->fixture('ocr_fattura.png'), 'e.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);

        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $stato = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/analisi", token: $this->login($viewer));
        $this->assertStatus(200);
        // Il testo vuoto, indicizzato senza vettori, è finito: non resta "da analizzare".
        self::assertSame(['totale' => 5, 'analizzati' => 2, 'daAnalizzare' => 1, 'testoInLettura' => 1, 'senzaTesto' => 0, 'cifrati' => 1], array_diff_key($stato, ['modelli' => 1]));
        self::assertSame(['dati', 'ricerca'], array_keys($stato['modelli']));

        $estraneo = $this->createUser('estraneo@example.com');
        $this->request('GET', "/api/tenants/{$this->tid()}/documenti/analisi", token: $this->login($estraneo));
        $this->assertStatus(403);
    }

    public function testAnalyzeMissingNeedsWriteAndQueuesOnlyThisFamily(): void
    {
        $fatto = $this->documento('bollette', 'a.png', self::BOLLETTA);
        $mancante = $this->documento('scuola', 'b.png', self::PAGELLA);
        $this->documento('salute', 'c.png', 'referto');
        $this->indicizza($fatto, []);
        $altra = $this->createTenant('Famiglia Bianchi');
        $altroOwner = $this->createUser('bianchi@example.com');
        $this->addMember($altroOwner, $altra, Role::OWNER);
        $this->documento('scuola', 'x.png', self::PAGELLA, $altra, $this->login($altroOwner));

        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/analisi", token: $this->login($viewer));
        $this->assertStatus(403);

        $this->queue()->reset();
        self::assertSame(['inCoda' => 1], $this->request('POST', "/api/tenants/{$this->tid()}/documenti/analisi", token: $this->token));
        $this->assertStatus(202);
        self::assertSame([$mancante], array_map(static fn ($e) => $e->getMessage()->documentoId, $this->queue()->getSent()));
        self::assertCount(1, $this->auditRows('documento.analisi_richiesta'));
    }

    public function testProgressWithLimitedEmergencyAccessCountsOnlyGrantedCategories(): void
    {
        $this->documento('bollette', 'a.png', self::BOLLETTA);
        $this->documento('scuola', 'b.png', self::PAGELLA);
        $emergenza = $this->createUser('emergenza@example.com');
        $this->em()->persist(Membership::perEmergenza($this->fresh($emergenza), $this->fresh($this->tenant),$this->systemRole(Role::EMERGENZA), [$this->categoria('scuola')->getId()->toRfc4122()]));
        $this->em()->flush();

        $stato = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/analisi", token: $this->login($emergenza));
        $this->assertStatus(200);
        self::assertSame(1, $stato['totale']);
        self::assertSame(1, $stato['daAnalizzare']);
    }

    /** Carica un documento con il testo OCR già pronto. */
    private function documento(string $categoria, string $nome, string $testo, ?Tenant $tenant = null, ?string $token = null): string
    {
        $tenant ??= $this->tenant;
        $doc = $this->upload((string) $tenant->getId(), $token ?? $this->token, $this->fixture('ocr_fattura.png'), $nome, ['categoriaId' => (string) $this->categoria($categoria)->getId()]);
        $this->assertStatus(201);
        $entity = $this->entity($doc['id']);
        static::getContainer()->get(DocumentService::class)->storeOcrText($entity, $testo);
        $this->em()->flush();

        return $doc['id'];
    }

    /** @param array<string, mixed>|null $risposta risposta del modello all'estrazione (null = modello non scaricato) */
    private function indicizza(string $id, ?array $risposta): void
    {
        $http = new MockHttpClient(static fn () => null === $risposta
            ? new MockResponse('{"error":"model not found"}', ['http_code' => 404])
            : new MockResponse(json_encode(['message' => ['content' => json_encode($risposta + ['emittente' => '', 'data' => '', 'importo' => '', 'numero' => '', 'intestatario' => '', 'riferimenti' => [], 'tag' => []])]])));
        $c = static::getContainer();
        $handler = new IndicizzaDocumentoHandler(
            $c->get(EntityManagerInterface::class),
            $c->get(DocumentService::class),
            $c->get(IndiceDocumenti::class),
            new AiEstrattoreDati($http, 'http://ollama.test:11434', 'm', 6000),
            $this->embedder(),
            $c->get(DocumentAudit::class),
            new NullLogger(),
            $c->get(MessageBusInterface::class),
        );
        $handler(new IndicizzaDocumentoMessage($id));
    }

    private function parti(string $id): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM documento_embedding WHERE documento_id = :id', ['id' => $id]);
    }

    private function ultimo(Tenant $tenant): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT id FROM documento WHERE tenant_id = :t ORDER BY created_at DESC, id DESC LIMIT 1', ['t' => (string) $tenant->getId()]);
    }

    private function entity(string $id): Documento
    {
        $this->em()->clear();

        return $this->em()->find(Documento::class, Uuid::fromString($id));
    }

    private function embedder(): FakeEmbedder
    {
        return static::getContainer()->get(FakeEmbedder::class);
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }

    private function queue(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }
}
