<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Scadenze\AiScadenzaSuggeritore;
use App\Scadenze\Message\ProponiScadenzaMessage;
use App\Scadenze\MessageHandler\ProponiScadenzaHandler;
use App\Scadenze\ScadenzaEstrattore;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Fase 7.1: scadenze proposte dai documenti, mai create senza conferma;
 * cifrate come il testo OCR nei documenti cifrati.
 */
final class ScadenzaPropostaTest extends ApiTestCase
{
    private const TESTO = "CARTA DI IDENTITA\nNASCITA 12/03/1980\nSCADENZA 12.03.2036";

    private Tenant $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
    }

    public function testProposalFromOcrIsEncryptedForSensitiveDocsAndClosedWhenAccepted(): void
    {
        $token = $this->login($this->owner);
        $id = $this->documento($token, 'salute', 'carta identità.png');

        // Dopo l'OCR il job viene messo in coda.
        $this->queue()->reset();
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new DocumentoOcrCompletatoEvent($this->entity($id)));
        self::assertNotEmpty(array_filter($this->queue()->getSent(), static fn ($e) => $e->getMessage() instanceof ProponiScadenzaMessage));

        $this->handler(ai: false)(new ProponiScadenzaMessage($id));
        $raw = $this->entity($id)->getScadenzaPropostaRaw();
        self::assertStringStartsWith('enc:', (string) $raw);
        self::assertStringNotContainsString('2036', (string) $raw, 'documento cifrato: la data estratta non va in chiaro');

        $dettaglio = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/$id", token: $token);
        self::assertSame(['2036-03-12', null, 'Scadenza carta identità'], [$dettaglio['scadenzaProposta']['data'], $dettaglio['scadenzaProposta']['tipo'], $dettaglio['scadenzaProposta']['titoloSuggerito']]);
        self::assertSame([$id], array_column($this->request('GET', "/api/tenants/{$this->tid()}/scadenze-proposte", token: $token), 'documentoId'));
        self::assertSame(0, $this->countScadenze(), 'nessuna scadenza senza conferma');

        // Conferma = scadenza normale collegata al documento: la proposta si chiude.
        $this->request('POST', "/api/tenants/{$this->tid()}/scadenze", [
            'titolo' => 'Scadenza carta d\'identità', 'data' => '2036-03-12', 'ricorrenza' => 'nessuna', 'promemoriaGiorni' => 30, 'documentoId' => $id,
        ], $token);
        $this->assertStatus(201);
        self::assertSame(Documento::PROPOSTA_ACCETTATA, $this->entity($id)->getScadenzaPropostaStato());
        self::assertNull($this->entity($id)->getScadenzaPropostaRaw());
        self::assertSame([], $this->request('GET', "/api/tenants/{$this->tid()}/scadenze-proposte", token: $token));

        // Un documento con una scadenza collegata non riceve altre proposte.
        $this->handler(ai: false)(new ProponiScadenzaMessage($id));
        self::assertSame(Documento::PROPOSTA_ACCETTATA, $this->entity($id)->getScadenzaPropostaStato());
    }

    public function testAiPicksTypeAndIgnoreHidesProposal(): void
    {
        $token = $this->login($this->owner);
        $id = $this->documento($token, 'casa', 'polizza.png');
        $this->handler(ai: true, risposta: '{"scadenza":"2036-03-12","tipo":"Polizza casa"}')(new ProponiScadenzaMessage($id));

        self::assertStringContainsString('2036-03-12', (string) $this->entity($id)->getScadenzaPropostaRaw(), 'non cifrato: JSON in chiaro come il testo OCR');
        $p = $this->request('GET', "/api/tenants/{$this->tid()}/scadenze-proposte", token: $token)[0];
        self::assertSame(['Polizza casa', 'Scadenza Polizza casa'], [$p['tipo'], $p['titoloSuggerito']]);
        $contesto = json_decode((string) $this->auditRows('documento.scadenza_proposta')[0]['context'], true);
        self::assertSame('ai', $contesto['metodo'] ?? null);
        self::assertStringNotContainsString('2036', json_encode($contesto), 'nell\'audit la data non compare');

        // Un visualizzatore non gestisce scadenze: non vede proposte e non può ignorarle.
        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $vt = $this->login($viewer);
        self::assertSame([], $this->request('GET', "/api/tenants/{$this->tid()}/scadenze-proposte", token: $vt));
        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/$id/scadenza-proposta/ignora", token: $vt);
        $this->assertStatus(403);

        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/$id/scadenza-proposta/ignora", token: $token);
        $this->assertStatus(200);
        self::assertNull($this->request('GET', "/api/tenants/{$this->tid()}/documenti/$id", token: $token)['scadenzaProposta']);
        self::assertSame(0, $this->countScadenze());
    }

    public function testOllamaDownFallsBackToRulesAndAiNoneMeansNoProposal(): void
    {
        $token = $this->login($this->owner);
        $id = $this->documento($token, 'casa', 'a.png');
        $this->handler(ai: true, risposta: null)(new ProponiScadenzaMessage($id));
        self::assertSame(Documento::PROPOSTA_APERTA, $this->entity($id)->getScadenzaPropostaStato());

        $id2 = $this->documento($token, 'casa', 'b.png');
        $this->handler(ai: true, risposta: '{"scadenza":"nessuna","tipo":""}')(new ProponiScadenzaMessage($id2));
        self::assertNull($this->entity($id2)->getScadenzaPropostaStato());
    }

    private function documento(string $token, string $categoria, string $nome): string
    {
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), $nome, ['categoriaId' => (string) $this->categoria($categoria)->getId()]);
        $this->assertStatus(201);
        $entity = $this->entity($doc['id']);
        static::getContainer()->get(DocumentService::class)->storeOcrText($entity, self::TESTO);
        $this->em()->flush();

        return $doc['id'];
    }

    private function handler(bool $ai, ?string $risposta = null): ProponiScadenzaHandler
    {
        $http = new MockHttpClient(static fn () => null === $risposta
            ? new MockResponse('down', ['http_code' => 503])
            : new MockResponse(json_encode(['message' => ['content' => $risposta]])));
        $c = static::getContainer();

        return new ProponiScadenzaHandler(
            $c->get(EntityManagerInterface::class),
            $c->get(DocumentService::class),
            new ScadenzaEstrattore(),
            new AiScadenzaSuggeritore($http, 'http://ollama.test:11434', $ai ? 'm' : '', 6000),
            $c->get(DocumentAudit::class),
            new NullLogger(),
            $c->get(MessageBusInterface::class),
        );
    }

    private function entity(string $id): Documento
    {
        $this->em()->clear();

        return $this->em()->find(Documento::class, Uuid::fromString($id));
    }

    private function countScadenze(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM scadenza');
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
