<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Pratiche\AiPagamentoSuggeritore;
use App\Pratiche\AvvisoPagamentoEstrattore;
use App\Pratiche\Message\ProponiPagamentoMessage;
use App\Pratiche\MessageHandler\ProponiPagamentoHandler;
use App\Tenancy\Entity\Membership;
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
 * Pratiche, fase 1: pagamenti. API, permessi, visibilità per categoria,
 * proposte dagli avvisi trovati nei documenti (cifrate nei documenti cifrati)
 * e scadenza collegata.
 */
final class PagamentoTest extends ApiTestCase
{
    private static function avviso(): string
    {
        return "COMUNE DI MILANO\nAvviso di pagamento TARI 2026\nEnte creditore: Comune di Milano\nCodice fiscale ente creditore 01199250158\nCodice avviso 3012 0000 4567 8912 34\nImporto da pagare € 180,40\nDa pagare entro il ".(new \DateTimeImmutable('+2 months'))->format('d/m/Y')."\nPaga con pagoPA";
    }

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

    public function testCreateListOrderingAndTotal(): void
    {
        $senza = $this->crea(['titolo' => 'Senza data', 'importo' => '10.00', 'promemoria' => false]);
        $tardi = $this->crea(['titolo' => 'Tardi', 'importo' => '20,50', 'scadenza' => '2099-12-31']);
        $presto = $this->crea(['titolo' => 'Presto', 'importo' => 5, 'scadenza' => '2099-01-15', 'codiceAvviso' => '3012 0000 4567 8912 34', 'codiceFiscaleEnte' => '01199250158', 'ente' => 'Comune']);
        self::assertSame(['20.50', 'manuale', 'da_pagare'], [$tardi['importo'], $tardi['origine'], $tardi['stato']]);
        self::assertSame(['5.00', '301200004567891234', '01199250158', 'Comune'], [$presto['importo'], $presto['codiceAvviso'], $presto['codiceFiscaleEnte'], $presto['ente']]);
        self::assertNull($senza['scadenzaId']);
        self::assertNotNull($presto['scadenzaId']);

        $r = $this->request('GET', $this->url('/pagamenti'), token: $this->token);
        self::assertSame(['Presto', 'Tardi', 'Senza data'], array_column($r['pagamenti'], 'titolo'));
        self::assertSame('35.50', $r['totaleDaPagare']);

        // Pagati: per data di pagamento decrescente; il totale conta solo i da pagare.
        $this->request('POST', $this->url("/pagamenti/{$tardi['id']}/pagato"), ['pagatoIl' => '2026-10-01'], $this->token);
        $this->request('POST', $this->url("/pagamenti/{$presto['id']}/pagato"), ['pagatoIl' => '2026-11-01'], $this->token);
        $r = $this->request('GET', $this->url('/pagamenti?stato=pagato'), token: $this->token);
        self::assertSame(['Presto', 'Tardi'], array_column($r['pagamenti'], 'titolo'));
        self::assertSame('10.00', $r['totaleDaPagare']);
        self::assertSame(['Senza data'], array_column($this->request('GET', $this->url('/pagamenti?stato=da_pagare'), token: $this->token)['pagamenti'], 'titolo'));
        $this->request('GET', $this->url('/pagamenti?stato=boh'), token: $this->token);
        $this->assertStatus(422);
    }

    public function testValidationMessagesAre422InItalian(): void
    {
        foreach ([
            [['titolo' => 'X', 'importo' => '0'], 'L\'importo deve essere maggiore di zero.'],
            [['titolo' => 'X', 'importo' => '-5'], 'L\'importo deve essere maggiore di zero.'],
            [['titolo' => 'X', 'importo' => 'abc'], 'L\'importo non è valido'],
            [['titolo' => 'X'], 'L\'importo è obbligatorio.'],
            [['titolo' => ' ', 'importo' => '1'], 'Il titolo è obbligatorio.'],
            [['titolo' => 'X', 'importo' => '1', 'codiceAvviso' => '12345678901234567'], 'Il codice avviso ha 18 cifre.'],
            [['titolo' => 'X', 'importo' => '1', 'codiceAvviso' => '1234567890123456789'], 'Il codice avviso ha 18 cifre.'],
            [['titolo' => 'X', 'importo' => '1', 'codiceFiscaleEnte' => '123'], 'Il codice fiscale dell\'ente ha 11 cifre.'],
            [['titolo' => 'X', 'importo' => '1', 'scadenza' => '30/11/2026'], 'formato AAAA-MM-GG'],
            [['titolo' => 'X', 'importo' => '1', 'documentoId' => (string) Uuid::v7()], 'non è stato trovato'],
        ] as [$corpo, $messaggio]) {
            $r = $this->request('POST', $this->url('/pagamenti'), $corpo, $this->token);
            $this->assertStatus(422);
            self::assertStringContainsString($messaggio, json_encode($r, \JSON_UNESCAPED_UNICODE), json_encode($corpo));
        }
        self::assertSame(0, $this->righe('pagamento'));
    }

    public function testPatchKeepsLinkedScadenzaInSyncAndPagatoClosesIt(): void
    {
        $p = $this->crea(['titolo' => 'Bollo auto', 'importo' => '100.00', 'scadenza' => '2099-05-31']);
        $s = $this->scadenza($p['scadenzaId']);
        self::assertSame(['Pagare: Bollo auto', '2099-05-31', 7, 'aperta'], [$s['titolo'], $s['data'], $s['promemoriaGiorni'], $s['stato']]);

        $p = $this->request('PATCH', $this->url("/pagamenti/{$p['id']}"), ['titolo' => 'Bollo auto 2099', 'scadenza' => '2099-06-30', 'importo' => '101.50'], $this->token);
        $this->assertStatus(200);
        self::assertSame('101.50', $p['importo']);
        $s = $this->scadenza($p['scadenzaId']);
        self::assertSame(['Pagare: Bollo auto 2099', '2099-06-30'], [$s['titolo'], $s['data']]);

        $this->request('POST', $this->url("/pagamenti/{$p['id']}/pagato"), [], $this->token);
        $this->assertStatus(200);
        self::assertSame('fatta', $this->scadenza($p['scadenzaId'])['stato']);
        $this->request('POST', $this->url("/pagamenti/{$p['id']}/pagato"), [], $this->token);
        $this->assertStatus(409);

        $p = $this->request('POST', $this->url("/pagamenti/{$p['id']}/da-pagare"), token: $this->token);
        $this->assertStatus(200);
        self::assertSame(['da_pagare', null], [$p['stato'], $p['pagatoIl']]);
        self::assertSame('aperta', $this->scadenza($p['scadenzaId'])['stato']);

        // Senza scadenza la scadenza collegata aperta sparisce.
        $p = $this->request('PATCH', $this->url("/pagamenti/{$p['id']}"), ['scadenza' => null], $this->token);
        self::assertNull($p['scadenza']);
        self::assertNull($p['scadenzaId']);
        self::assertSame(0, $this->righe('scadenza'));
    }

    public function testPagatoWithReceiptAndFutureDateRejected(): void
    {
        $ricevuta = $this->documento('casa', 'ricevuta.png');
        $p = $this->crea(['titolo' => 'Mensa', 'importo' => '30.00']);
        $this->request('POST', $this->url("/pagamenti/{$p['id']}/pagato"), ['pagatoIl' => '2099-01-01'], $this->token);
        $this->assertStatus(422);
        $this->request('POST', $this->url("/pagamenti/{$p['id']}/pagato"), ['ricevutaDocumentoId' => (string) Uuid::v7()], $this->token);
        $this->assertStatus(422);
        $p = $this->request('POST', $this->url("/pagamenti/{$p['id']}/pagato"), ['pagatoIl' => '2026-11-10', 'ricevutaDocumentoId' => $ricevuta], $this->token);
        $this->assertStatus(200);
        self::assertSame(['pagato', '2026-11-10', $ricevuta], [$p['stato'], $p['pagatoIl'], $p['ricevuta']['id']]);
        $dettaglio = $this->request('GET', $this->url("/documenti/$ricevuta"), token: $this->token);
        self::assertSame([['id' => $p['id'], 'titolo' => 'Mensa', 'stato' => 'pagato', 'ruolo' => 'ricevuta']], $dettaglio['pagamenti']);
    }

    public function testDeleteRemovesOpenLinkedScadenzaButKeepsDoneOne(): void
    {
        $aperto = $this->crea(['titolo' => 'Aperto', 'importo' => '1.00', 'scadenza' => '2099-01-01']);
        $fatto = $this->crea(['titolo' => 'Fatto', 'importo' => '1.00', 'scadenza' => '2099-02-02']);
        $this->request('POST', $this->url("/pagamenti/{$fatto['id']}/pagato"), [], $this->token);
        self::assertSame(2, $this->righe('scadenza'));

        $this->request('DELETE', $this->url("/pagamenti/{$aperto['id']}"), token: $this->token);
        $this->assertStatus(204);
        self::assertSame(1, $this->righe('scadenza'));
        $this->request('DELETE', $this->url("/pagamenti/{$fatto['id']}"), token: $this->token);
        $this->assertStatus(204);
        self::assertSame([1, 0], [$this->righe('scadenza'), $this->righe('pagamento')]);
        $this->request('GET', $this->url("/pagamenti/{$fatto['id']}"), token: $this->token);
        $this->assertStatus(404);
    }

    public function testPermissionsViewerMemberAndOtherTenant(): void
    {
        $p = $this->crea(['titolo' => 'Del proprietario', 'importo' => '9.00']);

        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $vt = $this->login($viewer);
        self::assertCount(1, $this->request('GET', $this->url('/pagamenti'), token: $vt)['pagamenti']);
        $this->request('POST', $this->url('/pagamenti'), ['titolo' => 'X', 'importo' => '1'], $vt);
        $this->assertStatus(403);
        $this->request('DELETE', $this->url("/pagamenti/{$p['id']}"), token: $vt);
        $this->assertStatus(403);
        self::assertSame(['proposte' => []], $this->request('GET', $this->url('/pagamenti-proposte'), token: $vt));

        // Il membro gestisce i propri, non quelli degli altri.
        $membro = $this->createUser('anna@example.com');
        $this->addMember($membro, $this->tenant, Role::MEMBER);
        $mt = $this->login($membro);
        $suo = $this->request('POST', $this->url('/pagamenti'), ['titolo' => 'Di Anna', 'importo' => '2.00'], $mt);
        $this->assertStatus(201);
        $this->request('PATCH', $this->url("/pagamenti/{$suo['id']}"), ['titolo' => 'Di Anna 2'], $mt);
        $this->assertStatus(200);
        $this->request('PATCH', $this->url("/pagamenti/{$p['id']}"), ['titolo' => 'Rubato'], $mt);
        $this->assertStatus(403);
        self::assertCount(2, $this->request('GET', $this->url('/pagamenti'), token: $mt)['pagamenti']);

        // Un'altra famiglia non vede nulla e riceve 404/403.
        $altra = $this->createTenant('Altra');
        $estraneo = $this->createUser('estraneo@example.com');
        $this->addMember($estraneo, $altra, Role::OWNER);
        $et = $this->login($estraneo);
        $this->request('GET', $this->url('/pagamenti'), token: $et);
        $this->assertStatus(403);
        $r = $this->request('GET', "/api/tenants/{$altra->getId()}/pagamenti", token: $et);
        self::assertSame([], $r['pagamenti']);
        $this->request('GET', "/api/tenants/{$altra->getId()}/pagamenti/{$p['id']}", token: $et);
        $this->assertStatus(404);
        $this->request('DELETE', "/api/tenants/{$altra->getId()}/pagamenti/{$p['id']}", token: $et);
        $this->assertStatus(404);
    }

    public function testEmergencyContactWithLimitedCategoriesNeverSeesOtherPayments(): void
    {
        $salute = $this->documento('salute', 'ticket.png');
        $casa = $this->documento('casa', 'imu.png');
        $this->crea(['titolo' => 'Ticket', 'importo' => '36.00', 'documentoId' => $salute]);
        $imu = $this->crea(['titolo' => 'IMU', 'importo' => '300.00', 'documentoId' => $casa]);
        $this->crea(['titolo' => 'Libero', 'importo' => '1.00']);

        $zia = $this->createUser('zia@example.com');
        $m = Membership::perEmergenza($this->fresh($zia), $this->fresh($this->tenant), $this->systemRole(Role::EMERGENZA), [(string) $this->categoria('salute')->getId()]);
        $this->em()->persist($m);
        $this->em()->flush();
        $zt = $this->login($zia);

        $r = $this->request('GET', $this->url('/pagamenti'), token: $zt);
        self::assertEqualsCanonicalizing(['Ticket', 'Libero'], array_column($r['pagamenti'], 'titolo'));
        self::assertSame('37.00', $r['totaleDaPagare'], 'il totale non rivela i pagamenti nascosti');
        $this->request('GET', $this->url("/pagamenti/{$imu['id']}"), token: $zt);
        $this->assertStatus(404);
        $this->request('DELETE', $this->url("/pagamenti/{$imu['id']}"), token: $zt);
        $this->assertStatus(404);
        $this->request('POST', $this->url('/pagamenti'), ['titolo' => 'X', 'importo' => '1'], $zt);
        $this->assertStatus(403, 'solo lettura');
        self::assertCount(3, $this->request('GET', $this->url('/pagamenti'), token: $this->token)['pagamenti']);
    }

    public function testProposalsAreOnlyForWhoCanManagePayments(): void
    {
        $casa = $this->documento('casa', 'avviso.png');
        $this->handler(ai: false)(new ProponiPagamentoMessage($casa));
        $zia = $this->createUser('zia@example.com');
        $this->em()->persist(Membership::perEmergenza($this->fresh($zia), $this->fresh($this->tenant), $this->systemRole(Role::EMERGENZA), null));
        $this->em()->flush();
        self::assertCount(1, $this->request('GET', $this->url('/pagamenti-proposte'), token: $this->token)['proposte']);
        $zt = $this->login($zia);
        self::assertSame([], $this->request('GET', $this->url('/pagamenti-proposte'), token: $zt)['proposte']);
        $this->request('POST', $this->url("/documenti/$casa/pagamento-proposta/ignora"), token: $zt);
        $this->assertStatus(403);
        self::assertNull($this->request('GET', $this->url("/documenti/$casa"), token: $zt)['pagamentoProposta']);
    }

    public function testProposalFromOcrIsEncryptedForSensitiveDocsAndAcceptedWithPayment(): void
    {
        $id = $this->documento('salute', 'avviso.png');

        $this->queue()->reset();
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new DocumentoOcrCompletatoEvent($this->entity($id)));
        $inCoda = array_filter(array_map(static fn ($e) => $e->getMessage(), $this->queue()->getSent()), static fn ($m) => $m instanceof ProponiPagamentoMessage);
        self::assertCount(1, $inCoda);

        $this->handler(ai: false)(new ProponiPagamentoMessage($id));
        $raw = (string) $this->entity($id)->getPagamentoPropostaRaw();
        self::assertStringStartsWith('enc:', $raw);
        self::assertStringNotContainsString('301200004567891234', $raw, 'documento cifrato: l\'avviso non va in chiaro');
        $contesto = json_decode((string) $this->auditRows('documento.pagamento_proposto')[0]['context'], true);
        self::assertSame('regole', $contesto['metodo'] ?? null);
        self::assertStringNotContainsString('301200004567891234', json_encode($contesto));

        $proposte = $this->request('GET', $this->url('/pagamenti-proposte'), token: $this->token)['proposte'];
        self::assertSame([[
            'documentoId' => $id, 'documentoTitolo' => 'avviso', 'categoria' => ['id' => (string) $this->categoria('salute')->getId(), 'nome' => $this->categoria('salute')->getNome()],
            'titoloSuggerito' => 'Comune di Milano avviso di pagamento', 'ente' => 'Comune di Milano', 'importo' => '180.40', 'scadenza' => (new \DateTimeImmutable('+2 months'))->format('Y-m-d'),
            'codiceAvviso' => '301200004567891234', 'codiceFiscaleEnte' => '01199250158',
        ]], $proposte);
        $dettaglio = $this->request('GET', $this->url("/documenti/$id"), token: $this->token);
        self::assertSame($proposte[0], $dettaglio['pagamentoProposta']);
        self::assertSame(0, $this->righe('pagamento'), 'nessun pagamento senza conferma');

        // Conferma = POST /pagamenti con il documento: la proposta è accettata.
        $p = $this->request('POST', $this->url('/pagamenti'), [
            'titolo' => 'TARI 2026', 'importo' => '180.40', 'scadenza' => '2099-11-30', 'codiceAvviso' => '301200004567891234', 'documentoId' => $id,
        ], $this->token);
        $this->assertStatus(201);
        self::assertSame(['documento', $id], [$p['origine'], $p['documento']['id']]);
        self::assertSame(Documento::PROPOSTA_ACCETTATA, $this->entity($id)->getPagamentoPropostaStato());
        self::assertNull($this->entity($id)->getPagamentoPropostaRaw());
        self::assertSame([], $this->request('GET', $this->url('/pagamenti-proposte'), token: $this->token)['proposte']);
        $dettaglio = $this->request('GET', $this->url("/documenti/$id"), token: $this->token);
        self::assertSame([['id' => $p['id'], 'titolo' => 'TARI 2026', 'stato' => 'da_pagare', 'ruolo' => 'avviso']], $dettaglio['pagamenti']);

        // Un documento con un pagamento collegato non riceve altre proposte.
        $this->handler(ai: false)(new ProponiPagamentoMessage($id, true));
        self::assertSame(Documento::PROPOSTA_ACCETTATA, $this->entity($id)->getPagamentoPropostaStato());
    }

    /** "Da confermare" non mostra due proposte per lo stesso avviso (scadenza e pagamento). */
    public function testOpenPaymentProposalHidesTheSameDocumentDeadlineProposal(): void
    {
        $id = $this->documento('casa', 'avviso.png');
        $this->handler(ai: false)(new ProponiPagamentoMessage($id));
        $documents = static::getContainer()->get(DocumentService::class);
        $d = $this->entity($id);
        $documents->storeScadenzaProposta($d, ['data' => '2099-11-30', 'tipo' => null]);
        $this->em()->flush();

        $scadenze = fn () => array_column($this->request('GET', $this->url('/scadenze-proposte'), token: $this->token), 'documentoId');
        self::assertNotContains($id, $scadenze(), 'con l\'avviso da confermare la scadenza non si propone a parte');

        $this->request('POST', $this->url("/documenti/$id/pagamento-proposta/ignora"), token: $this->token);
        $this->assertStatus(204);
        self::assertContains($id, $scadenze(), 'avviso ignorato: la scadenza torna proponibile');
    }

    public function testAiTitleIgnoreAndRevaluation(): void
    {
        $id = $this->documento('casa', 'avviso.png');
        $this->handler(ai: true, risposta: '{"titolo":"TARI 2026, seconda rata","ente":"Comune di Milano"}')(new ProponiPagamentoMessage($id));
        self::assertStringContainsString('301200004567891234', (string) $this->entity($id)->getPagamentoPropostaRaw(), 'non cifrato: JSON in chiaro come il testo OCR');
        self::assertSame('ai', json_decode((string) $this->auditRows('documento.pagamento_proposto')[0]['context'], true)['metodo']);
        $p = $this->request('GET', $this->url('/pagamenti-proposte'), token: $this->token)['proposte'][0];
        self::assertSame(['TARI 2026, seconda rata', '180.40'], [$p['titoloSuggerito'], $p['importo']]);

        $this->request('POST', $this->url("/documenti/$id/pagamento-proposta/ignora"), token: $this->token);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', $this->url('/pagamenti-proposte'), token: $this->token)['proposte']);
        self::assertNotEmpty($this->auditRows('documento.pagamento_proposta_ignorata'));
        // Ignorata: il normale invio dopo l'OCR non la ripropone, il comando con --tutti sì.
        $this->handler(ai: false)(new ProponiPagamentoMessage($id));
        self::assertSame(Documento::PROPOSTA_IGNORATA, $this->entity($id)->getPagamentoPropostaStato());
        $this->handler(ai: false)(new ProponiPagamentoMessage($id, true));
        self::assertSame(Documento::PROPOSTA_APERTA, $this->entity($id)->getPagamentoPropostaStato());
    }

    public function testOllamaDownFallsBackToRulesAndOrdinaryDocumentGetsNothing(): void
    {
        $id = $this->documento('casa', 'a.png');
        $this->handler(ai: true, risposta: null)(new ProponiPagamentoMessage($id));
        self::assertSame(Documento::PROPOSTA_APERTA, $this->entity($id)->getPagamentoPropostaStato());
        self::assertSame('Comune di Milano avviso di pagamento', $this->request('GET', $this->url('/pagamenti-proposte'), token: $this->token)['proposte'][0]['titoloSuggerito']);

        $altro = $this->documento('casa', 'b.png', 'Verbale di assemblea del 12/03/2026.');
        $this->handler(ai: true, risposta: '{"titolo":"x","ente":""}')(new ProponiPagamentoMessage($altro));
        self::assertNull($this->entity($altro)->getPagamentoPropostaStato());
    }

    public function testAuditHasNoAmountsOrCodes(): void
    {
        $p = $this->crea(['titolo' => 'Audit', 'importo' => '123.45', 'codiceAvviso' => '301200004567891234', 'scadenza' => '2099-01-01']);
        $this->request('PATCH', $this->url("/pagamenti/{$p['id']}"), ['importo' => '124.00'], $this->token);
        $this->request('POST', $this->url("/pagamenti/{$p['id']}/pagato"), [], $this->token);
        $this->request('DELETE', $this->url("/pagamenti/{$p['id']}"), token: $this->token);
        $righe = [];
        foreach (['pagamento.creato', 'pagamento.modificato', 'pagamento.pagato', 'pagamento.eliminato'] as $azione) {
            self::assertCount(1, $this->auditRows($azione), $azione);
            $righe[] = $this->auditRows($azione)[0]['context'];
        }
        $tutto = implode(' ', $righe);
        foreach (['123', '124', '3012'] as $segreto) {
            self::assertStringNotContainsString($segreto, $tutto);
        }
    }

    /** @param array<string, mixed> $corpo */
    private function crea(array $corpo): array
    {
        $r = $this->request('POST', $this->url('/pagamenti'), $corpo, $this->token);
        $this->assertStatus(201);

        return $r;
    }

    /** @return array<string, mixed> */
    private function scadenza(?string $id): array
    {
        $r = $this->request('GET', $this->url("/scadenze/$id"), token: $this->token);
        $this->assertStatus(200);

        return $r;
    }

    private function documento(string $categoria, string $nome, ?string $testo = null): string
    {
        $testo ??= self::avviso();
        $doc = $this->upload($this->tid(), $this->token, $this->fixture('ocr_fattura.png').$nome, $nome, ['categoriaId' => (string) $this->categoria($categoria)->getId()]);
        $this->assertStatus(201);
        static::getContainer()->get(DocumentService::class)->storeOcrText($this->entity($doc['id']), $testo);
        $this->em()->flush();

        return $doc['id'];
    }

    private function handler(bool $ai, ?string $risposta = null): ProponiPagamentoHandler
    {
        $http = new MockHttpClient(static fn () => null === $risposta
            ? new MockResponse('down', ['http_code' => 503])
            : new MockResponse(json_encode(['message' => ['content' => $risposta]])));
        $c = static::getContainer();

        return new ProponiPagamentoHandler(
            $c->get(EntityManagerInterface::class),
            $c->get(DocumentService::class),
            new AvvisoPagamentoEstrattore(),
            new AiPagamentoSuggeritore($http, 'http://ollama.test:11434', $ai ? 'm' : '', 6000),
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

    private function righe(string $tabella): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM $tabella");
    }

    private function url(string $percorso): string
    {
        return "/api/tenants/{$this->tid()}$percorso";
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
