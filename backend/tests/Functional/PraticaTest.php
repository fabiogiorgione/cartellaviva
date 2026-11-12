<?php

namespace App\Tests\Functional;

use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\User;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Pratiche\AiVociSuggeritore;
use App\Pratiche\EstrattoreVoci;
use App\Pratiche\Message\AnalizzaDocumentoPraticaMessage;
use App\Pratiche\MessageHandler\AnalizzaDocumentoPraticaHandler;
use App\Pratiche\Modello\ModelliPratica;
use App\Pratiche\PraticaCifratura;
use App\Pratiche\Repository\PraticaRepository;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Pratiche, fase 2a: motore comune con il 730. Avvio, analisi dei documenti
 * (in chiaro subito, cifrati solo con il consenso), revisione delle voci,
 * cosa manca, riepilogo, permessi, valori cifrati, pacchetto e archivio.
 */
final class PraticaTest extends ApiTestCase
{
    private const SCONTRINO = "FARMACIA CENTRALE SNC\nScontrino parlante\nFarmaco 12,40\nTOTALE EURO 14,40\nPagamento elettronico\n12/03/2025 10:41";
    private const VISITA = "Studio medico dott. Bianchi\nFattura sanitaria n. 12\nVisita specialistica cardiologica\nTotale 120,00 €\nPagato in contanti\n05/06/2025";

    private Tenant $tenant;
    private User $owner;
    private string $token;
    private MembroFamiglia $mario;
    private MembroFamiglia $luca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
        $this->token = $this->login($this->owner);
        $this->mario = $this->persona('Mario', 'Rossi');
        $this->luca = $this->persona('Luca', 'Rossi');
    }

    public function testModelsAndStartValidation(): void
    {
        $m = $this->request('GET', $this->url('/pratiche-modelli'), token: $this->token);
        $this->assertStatus(200);
        $anni = array_column(array_filter($m['modelli'], static fn ($x) => '730' === $x['modello']), 'provvisorio', 'anno');
        self::assertSame(false, $anni[2025]);
        self::assertSame(true, $anni[2026]);

        foreach ([
            [['modello' => 'boh', 'anno' => 2025, 'persone' => [$this->id($this->mario)]], 'Scegli il tipo'],
            [['modello' => '730', 'anno' => 1999, 'persone' => [$this->id($this->mario)]], 'non ci sono ancora le regole'],
            [['modello' => '730', 'anno' => 2025, 'persone' => []], 'almeno una persona'],
            [['modello' => '730', 'anno' => 2025, 'persone' => [(string) Uuid::v7()]], 'non è nella famiglia'],
        ] as [$corpo, $msg]) {
            $r = $this->request('POST', $this->url('/pratiche'), $corpo, $this->token);
            $this->assertStatus(422);
            self::assertStringContainsString($msg, json_encode($r, \JSON_UNESCAPED_UNICODE));
        }
        $p = $this->avvia();
        self::assertSame(['730', 2025, 'in_corso', '730/2026 · spese 2025'], [$p['modello'], $p['anno'], $p['stato'], $p['titolo']]);
        self::assertTrue($p['puoGestire']);
        $this->request('POST', $this->url('/pratiche'), ['modello' => '730', 'anno' => 2025, 'persone' => [$this->id($this->mario)]], $this->token);
        $this->assertStatus(409);
        self::assertCount(1, $this->auditRows('pratica.avviata'));
    }

    public function testAnalysisReviewConsentSummaryAndPackage(): void
    {
        $scontrino = $this->documento('farmaci', 'scontrino.png', self::SCONTRINO, '2025-03-12', [$this->mario]);
        $visita = $this->documento('casa', 'visita.png', self::VISITA, '2025-06-05');
        $this->documento('casa', 'vecchio.png', str_replace('2025', '2023', self::VISITA), '2023-06-05');
        $p = $this->avvia();
        // Farmaci è una categoria cifrata: senza consenso il documento si conta e basta.
        self::assertSame(['inCoda' => 1, 'analizzati' => 0, 'cifratiInAttesa' => 1], $p['analisi']);
        $this->esegui($p['id']);

        $p = $this->dettaglio($p['id']);
        self::assertSame(['inCoda' => 0, 'analizzati' => 1, 'cifratiInAttesa' => 1], $p['analisi']);
        self::assertCount(1, $p['voci']);
        $v = $p['voci'][0];
        self::assertSame(['spesa_sanitaria', 'proposta', null, $visita], [$v['requisito'], $v['stato'], $v['personaId'], $v['documento']['id']]);
        self::assertEquals(['importo' => '120.00', 'data' => '2025-06-05', 'tipo' => 'visite', 'tracciabile' => 'no'], $v['valori']);
        self::assertSame(['persona', 'tracciabile'], array_column($v['controlli'], 'codice'));
        self::assertSame('info', $v['controlli'][1]['livello']);
        $proposte = $this->request('GET', $this->url('/pratiche-proposte'), token: $this->token)['proposte'];
        self::assertSame([[$v['id'], $p['id'], '730/2026 · spese 2025', 'Spese sanitarie', 'Visite ed esami']], array_map(static fn ($x) => [$x['id'], $x['praticaId'], $x['praticaTitolo'], $x['requisitoEtichetta'], $x['tipoEtichetta']], $proposte));

        // Correggi e conferma: la persona e il tipo; in contanti resta l'avviso.
        $v = $this->request('PATCH', $this->url("/pratiche/{$p['id']}/voci/{$v['id']}"), ['personaId' => $this->id($this->luca), 'valori' => ['tipo' => 'altro'], 'conferma' => true], $this->token);
        $this->assertStatus(200);
        self::assertSame(['confermata', $this->id($this->luca), 'altro'], [$v['stato'], $v['personaId'], $v['valori']['tipo']]);
        self::assertSame([['tracciabile', 'avviso']], array_map(static fn ($c) => [$c['codice'], $c['livello']], $v['controlli']));
        $this->request('PATCH', $this->url("/pratiche/{$p['id']}/voci/{$v['id']}"), ['personaId' => (string) Uuid::v7()], $this->token);
        $this->assertStatus(422);
        $this->request('PATCH', $this->url("/pratiche/{$p['id']}/voci/{$v['id']}"), ['valori' => ['tipo' => 'boh']], $this->token);
        $this->assertStatus(422);

        // Consenso per i cifrati: ora si analizzano, la persona è quella del documento.
        $p = $this->request('POST', $this->url("/pratiche/{$p['id']}/consenso-cifrati"), ['consenso' => true], $this->token);
        $this->assertStatus(200);
        self::assertNotNull($p['consensoCifrati']);
        self::assertSame(['inCoda' => 1, 'analizzati' => 1, 'cifratiInAttesa' => 0], $p['analisi']);
        $this->esegui($p['id']);
        $p = $this->dettaglio($p['id']);
        $f = array_values(array_filter($p['voci'], static fn ($x) => $x['documento']['id'] === $scontrino))[0];
        self::assertEquals([$this->id($this->mario), true, ['importo' => '14.40', 'data' => '2025-03-12', 'tipo' => 'farmaci', 'tracciabile' => 'si']], [$f['personaId'], $f['documento']['cifrato'], $f['valori']]);
        self::assertSame([], $f['controlli']);
        // A DB i valori sono cifrati.
        $blob = implode(' ', $this->em()->getConnection()->fetchFirstColumn('SELECT valori_cifrati FROM pratica_voce'));
        foreach (['14.40', '120.00', 'farmaci', 'visite'] as $chiaro) {
            self::assertStringNotContainsString($chiaro, $blob);
        }

        // Escludi, riproponi, conferma.
        foreach (['escludi' => 'esclusa', 'riproponi' => 'proposta', 'conferma' => 'confermata'] as $azione => $stato) {
            self::assertSame($stato, $this->request('POST', $this->url("/pratiche/{$p['id']}/voci/{$f['id']}/$azione"), token: $this->token)['stato']);
        }

        // Riepilogo: somme dei documenti confermati, per persona e per tipo.
        $p = $this->dettaglio($p['id']);
        self::assertSame('134.40', $p['riepilogo']['totale']);
        self::assertSame(['Luca Rossi' => '120.00', 'Mario Rossi' => '14.40'], array_column($p['riepilogo']['persone'], 'totale', 'persona'));
        self::assertSame(['Altre spese sanitarie' => '120.00', 'Farmaci' => '14.40'], array_column($p['riepilogo']['requisiti'][0]['tipi'], 'totale', 'etichetta'));

        // Cosa manca: la CU di entrambi; per Luca "non serve", per Mario la si aggiunge a mano.
        self::assertSame([['cu', $this->id($this->mario)], ['cu', $this->id($this->luca)]], array_map(static fn ($m) => [$m['requisito'], $m['personaId']], $p['cosaManca']));
        $p = $this->request('POST', $this->url("/pratiche/{$p['id']}/non-serve"), ['requisito' => 'cu', 'personaId' => $this->id($this->luca)], $this->token);
        self::assertSame([['requisito' => 'cu', 'personaId' => $this->id($this->luca), 'dettaglio' => null]], $p['nonServe']);
        $this->request('POST', $this->url("/pratiche/{$p['id']}/voci"), ['requisito' => 'cu', 'personaId' => $this->id($this->mario), 'valori' => []], $this->token);
        $this->assertStatus(422);
        $cu = $this->request('POST', $this->url("/pratiche/{$p['id']}/voci"), ['requisito' => 'cu', 'personaId' => $this->id($this->mario), 'valori' => ['emittente' => 'ACME Spa', 'importo' => '30000']], $this->token);
        $this->assertStatus(201);
        self::assertSame(['confermata', 'manuale'], [$cu['stato'], $cu['origine']]);
        $p = $this->dettaglio($p['id']);
        self::assertSame([], $p['cosaManca']);
        self::assertSame('134.40', $p['riepilogo']['totale'], 'la CU non si somma alle spese');

        // Una voce a mano uguale a quella della visita è un doppione.
        $doppia = $this->request('POST', $this->url("/pratiche/{$p['id']}/voci"), ['requisito' => 'spesa_sanitaria', 'personaId' => $this->id($this->luca), 'valori' => ['importo' => '120,00', 'data' => '2025-06-05', 'tipo' => 'altro', 'tracciabile' => 'si']], $this->token);
        self::assertContains('doppione', array_column($doppia['controlli'], 'codice'));
        $this->request('DELETE', $this->url("/pratiche/{$p['id']}/voci/{$doppia['id']}"), token: $this->token);
        $this->assertStatus(204);

        // Nuova analisi: niente doppie proposte.
        self::assertSame(['inCoda' => 0], $this->request('POST', $this->url("/pratiche/{$p['id']}/analizza"), token: $this->token));
        self::assertCount(3, $this->dettaglio($p['id'])['voci']);

        // Un documento nuovo dopo l'OCR finisce in coda per la pratica in corso.
        $nuovo = $this->documento('casa', 'ticket.png', "ASST Milano\nTicket sanitario\nImporto 36,15 €\n10/10/2025");
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new DocumentoOcrCompletatoEvent($this->em()->find(Documento::class, Uuid::fromString($nuovo))));
        self::assertCount(1, array_filter($this->queue()->getSent(), static fn ($e) => $e->getMessage() instanceof AnalizzaDocumentoPraticaMessage && $e->getMessage()->documentoId === $nuovo));

        // Pacchetto: ZIP al momento; "Archivia" lo salva cifrato in Condivisi con enti.
        $this->client->request('GET', $this->url("/pratiche/{$p['id']}/pacchetto"), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        $this->assertStatus(200);
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        $a = $this->request('POST', $this->url("/pratiche/{$p['id']}/archivia"), token: $this->token);
        $this->assertStatus(201);
        $doc = $this->em()->find(Documento::class, Uuid::fromString($a['documento']['id']));
        self::assertSame(['condivisi_enti', true], [$doc->getCategoria()->getSlug(), $doc->isCifrato()]);
        $file = tempnam(sys_get_temp_dir(), 'pacchetto');
        file_put_contents($file, static::getContainer()->get(DocumentService::class)->readContent($doc));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $nomi = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nomi[] = $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($file);
        sort($nomi);
        self::assertSame(['Luca Rossi/Spese sanitarie/2025-06-05 visita 120,00.png', 'Mario Rossi/Spese sanitarie/2025-03-12 scontrino 14,40.png', 'Riepilogo.pdf'], $nomi);

        // Audit: i fatti, mai i valori.
        $contesti = json_encode(array_merge(...array_map(fn ($a) => array_column($this->auditRows($a), 'context'), ['pratica.avviata', 'pratica.consenso_cifrati', 'pratica.voce_aggiunta', 'pratica.pacchetto'])));
        self::assertCount(2, $this->auditRows('pratica.pacchetto'));
        foreach (['120', '14,40', '14.40', 'ACME', '30000'] as $valore) {
            self::assertStringNotContainsString($valore, (string) $contesti);
        }
    }

    public function testPermissionsAndIsolation(): void
    {
        $p = $this->avvia();
        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $vt = $this->login($viewer);
        self::assertFalse($this->dettaglio($p['id'], $vt)['puoGestire']);
        self::assertFalse($this->request('GET', $this->url('/pratiche'), token: $vt)['puoAvviare']);
        $this->request('POST', $this->url("/pratiche/{$p['id']}/voci"), ['requisito' => 'cu', 'valori' => ['emittente' => 'X']], $vt);
        $this->assertStatus(403);
        $this->request('POST', $this->url('/pratiche'), ['modello' => '730', 'anno' => 2026, 'persone' => [$this->id($this->mario)]], $vt);
        $this->assertStatus(403);
        self::assertSame([], $this->request('GET', $this->url('/pratiche-proposte'), token: $vt)['proposte']);

        // Un membro gestisce le proprie pratiche, non quelle degli altri.
        $membro = $this->createUser('giulia@example.com');
        $this->addMember($membro, $this->tenant, Role::MEMBER);
        $mt = $this->login($membro);
        $this->request('DELETE', $this->url("/pratiche/{$p['id']}"), token: $mt);
        $this->assertStatus(403);
        $this->request('POST', $this->url('/pratiche'), ['modello' => '730', 'anno' => 2026, 'persone' => [$this->id($this->mario)]], $mt);
        $this->assertStatus(201);

        // Un'altra famiglia non vede nulla.
        $altra = $this->createTenant('Famiglia Bianchi');
        $estraneo = $this->createUser('bianchi@example.com');
        $this->addMember($estraneo, $altra, Role::OWNER);
        $et = $this->login($estraneo);
        $this->request('GET', "/api/tenants/{$altra->getId()}/pratiche/{$p['id']}", token: $et);
        $this->assertStatus(404);
        $this->request('GET', $this->url("/pratiche/{$p['id']}"), token: $et);
        $this->assertStatus(403);

        $this->request('DELETE', $this->url("/pratiche/{$p['id']}"), token: $this->token);
        $this->assertStatus(204);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pratica WHERE id = :id', ['id' => $p['id']]));
    }

    public function testVociOfUnreadableDocumentsDoNotExistForTheUser(): void
    {
        $this->documento('casa', 'visita.png', self::VISITA, '2025-06-05');
        $p = $this->avvia();
        $this->esegui($p['id']);
        $voce = $this->dettaglio($p['id'])['voci'][0];
        $this->request('POST', $this->url("/pratiche/{$p['id']}/voci/{$voce['id']}/conferma"), token: $this->token);
        $this->assertStatus(200);

        // Contatto d'emergenza con accesso alla sola categoria Salute: la voce (da Casa) non c'è.
        $zia = $this->createUser('zia@example.com');
        $m = Membership::perEmergenza($this->fresh($zia), $this->fresh($this->tenant), $this->systemRole('emergenza'), [(string) $this->categoria('salute')->getId()]);
        $this->em()->persist($m);
        $this->em()->flush();
        $zt = $this->login($zia);
        $vista = $this->dettaglio($p['id'], $zt);
        self::assertSame([[], '0.00', 0, false], [$vista['voci'], $vista['riepilogo']['totale'], $vista['confermate'], $vista['puoGestire']]);
        $this->request('PATCH', $this->url("/pratiche/{$p['id']}/voci/{$voce['id']}"), ['valori' => ['importo' => '1']], $zt);
        $this->assertStatus(403);
    }

    public function testIseeAccountsDiscoveredIbansPrecompiledComparisonAndRenewal(): void
    {
        $this->documento('casa', 'banca.png', "BANCA DI ESEMPIO SPA\nComunicazione annuale ai fini ISEE\nMario Rossi\nConto corrente IBAN IT60 X054 2811 1010 0000 0123 456\nSaldo contabile al 31/12/2024 euro 3.210,55\nGiacenza media annua 2024 euro 2.874,10", '2025-02-10', [$this->mario]);
        // Un altro conto citato in un estratto conto, senza saldo: niente voce, ma "da completare".
        $this->documento('casa', 'estratto.png', "Estratto conto\nIBAN IT74 L123 4512 3451 2345 6789 018\nMovimenti del mese", '2025-03-01');
        // Una bolletta con l'IBAN del fornitore non c'entra.
        $this->documento('bollette', 'bolletta.png', "Bolletta luce\nPaga con bonifico su IBAN IT33X0542811101000000123457\nTotale 80,00 €", '2025-03-01');

        $r = $this->request('POST', $this->url('/pratiche'), ['modello' => 'isee', 'anno' => 2026, 'persone' => [$this->id($this->mario), $this->id($this->luca)]], $this->token);
        $this->assertStatus(201);
        self::assertSame(['ISEE 2026 · redditi e patrimonio 2024', 2024], [$r['titolo'], $r['annoRiferimento']]);
        $this->esegui($r['id']);
        $p = $this->dettaglio($r['id']);
        self::assertCount(1, $p['voci']);
        $conto = $p['voci'][0];
        self::assertSame(['rapporti', $this->id($this->mario)], [$conto['requisito'], $conto['personaId']]);
        self::assertEquals(['tipo' => 'conto', 'iban' => 'IT60X0542811101000000123456', 'importo' => '3210.55', 'giacenza' => '2874.10'], $conto['valori']);
        $scoperti = array_values(array_filter($p['cosaManca'], static fn ($m) => 'rapporti' === $m['requisito']));
        self::assertSame([['IT74L1234512345123456789018', ['iban' => 'IT74L1234512345123456789018']]], array_map(static fn ($m) => [$m['dettaglio'], $m['valori']], $scoperti));
        // Documento e redditi obbligatori per entrambi.
        self::assertSame(['documento_identita', 'documento_identita', 'redditi', 'redditi'], array_column(array_filter($p['cosaManca'], static fn ($m) => null === $m['dettaglio']), 'requisito'));

        // "Non serve" sull'IBAN scoperto (es. un conto chiuso).
        $p = $this->request('POST', $this->url("/pratiche/{$p['id']}/non-serve"), ['requisito' => 'rapporti', 'dettaglio' => 'IT74L1234512345123456789018'], $this->token);
        self::assertSame([], array_filter($p['cosaManca'], static fn ($m) => 'rapporti' === $m['requisito']));
        $this->request('POST', $this->url("/pratiche/{$p['id']}/non-serve"), ['requisito' => 'redditi', 'dettaglio' => 'X'], $this->token);
        $this->assertStatus(422);

        // Confronto con la DSU precompilata: il saldo coincide, la giacenza no.
        $v = $this->request('PATCH', $this->url("/pratiche/{$p['id']}/voci/{$conto['id']}"), ['valori' => ['saldo_precompilata' => '3210,55', 'giacenza_precompilata' => '2900'], 'conferma' => true], $this->token);
        $this->assertStatus(200);
        self::assertSame(['confronto'], array_column($v['controlli'], 'codice'));
        self::assertStringContainsString('2.900,00 € contro 2.874,10 €', $v['controlli'][0]['testo']);
        $this->request('PATCH', $this->url("/pratiche/{$p['id']}/voci/{$conto['id']}"), ['valori' => ['iban' => 'IT00X0000000000000000000000']], $this->token);
        $this->assertStatus(422);
        $this->request('POST', $this->url("/pratiche/{$p['id']}/voci"), ['requisito' => 'veicoli', 'personaId' => $this->id($this->mario), 'valori' => ['targa' => 'XYZ']], $this->token);
        $this->assertStatus(422);
        $auto = $this->request('POST', $this->url("/pratiche/{$p['id']}/voci"), ['requisito' => 'veicoli', 'personaId' => $this->id($this->mario), 'valori' => ['targa' => 'ab 123 cd', 'tipo' => 'auto']], $this->token);
        self::assertSame('AB123CD', $auto['valori']['targa']);
        self::assertSame('3210.55', $this->dettaglio($p['id'])['riepilogo']['totale']);

        // DSU presentata: nasce la scadenza del rinnovo al 31 dicembre, una volta sola.
        $p = $this->request('PATCH', $this->url("/pratiche/{$p['id']}"), ['stato' => 'inviata'], $this->token);
        self::assertSame('2026-12-31', $p['scadenzaRinnovo']['data']);
        $s = $this->request('GET', $this->url("/scadenze/{$p['scadenzaRinnovo']['id']}"), token: $this->token);
        self::assertSame(["Rinnovare l'ISEE", 30], [$s['titolo'], $s['promemoriaGiorni']]);
        $this->request('PATCH', $this->url("/pratiche/{$p['id']}"), ['stato' => 'in_corso'], $this->token);
        $this->request('PATCH', $this->url("/pratiche/{$p['id']}"), ['stato' => 'inviata'], $this->token);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM scadenza'));
        self::assertCount(2, $this->auditRows('pratica.inviata'));
    }

    public function testPhoneAnalysisGetsJobsAndOnlyCheckedValuesAreSaved(): void
    {
        $visita = $this->documento('casa', 'visita.png', self::VISITA, '2025-06-05');
        $r = $this->request('POST', $this->url('/pratiche'), ['modello' => '730', 'anno' => 2025, 'persone' => [$this->id($this->luca)], 'analisiSu' => 'telefono'], $this->token);
        $this->assertStatus(201);
        self::assertSame(['telefono', 1], [$r['analisiSu'], $r['analisi']['inCoda']]);
        // Il server non manda nulla a Ollama.
        self::assertSame([], array_filter($this->queue()->getSent(), static fn ($e) => $e->getMessage() instanceof AnalizzaDocumentoPraticaMessage));

        $l = $this->request('GET', $this->url("/pratiche/{$r['id']}/da-analizzare?caratteri=2000&limite=2"), token: $this->token);
        $this->assertStatus(200);
        self::assertSame(1, $l['restano']);
        $lavoro = $l['lavori'][0];
        self::assertSame($visita, $lavoro['documentoId']);
        self::assertStringContainsString('Visita specialistica', $lavoro['prompt']);
        self::assertStringContainsString('730/2026 · spese 2025', $lavoro['istruzioni']);
        $schema = json_decode($lavoro['schema'], true);
        self::assertSame('requisito', $schema['properties']['voci']['items']['x-order'][0]);

        // Il telefono manda la risposta del modello: l'importo inventato si corregge sul testo.
        $risposta = json_encode(['voci' => [['requisito' => 'spesa_sanitaria', 'tipo' => 'visite', 'persona' => '', 'importo' => '999,00', 'data' => '05/06/2025', 'emittente' => 'Studio medico dott. Bianchi', 'tracciabile' => 'si', 'descrizione' => 'Visita cardiologica']]]);
        $esito = $this->request('POST', $this->url("/pratiche/{$r['id']}/risultati"), ['documentoId' => $visita, 'risposta' => $risposta, 'modello' => 'apple-26'], $this->token);
        $this->assertStatus(200);
        self::assertSame(['voci' => 1], $esito);
        $v = $this->dettaglio($r['id'])['voci'][0];
        self::assertSame(['ai_telefono', '120.00', 'no', $this->id($this->luca)], [$v['origine'], $v['valori']['importo'], $v['valori']['tracciabile'], $v['personaId']]);
        $this->request('POST', $this->url("/pratiche/{$r['id']}/risultati"), ['documentoId' => $visita, 'risposta' => $risposta], $this->token);
        $this->assertStatus(409);
        self::assertSame(['lavori' => [], 'restano' => 0], $this->request('GET', $this->url("/pratiche/{$r['id']}/da-analizzare"), token: $this->token));

        // Un errore sul telefono si registra; "Cerca di nuovo" lo rimette tra i lavori.
        $altro = $this->documento('casa', 'ticket.png', "ASST Milano\nTicket sanitario\nImporto 36,15 €\n10/10/2025");
        $this->request('POST', $this->url("/pratiche/{$r['id']}/risultati"), ['documentoId' => $altro, 'errore' => 'exceededContextWindowSize'], $this->token);
        $this->assertStatus(200);
        self::assertSame(0, $this->request('GET', $this->url("/pratiche/{$r['id']}/da-analizzare"), token: $this->token)['restano']);
        self::assertSame(['inCoda' => 1], $this->request('POST', $this->url("/pratiche/{$r['id']}/analizza"), token: $this->token));
        self::assertSame([], array_filter($this->queue()->getSent(), static fn ($e) => $e->getMessage() instanceof AnalizzaDocumentoPraticaMessage));

        // Tornando al server, il documento rimasto va in coda per Ollama.
        $p = $this->request('PATCH', $this->url("/pratiche/{$r['id']}"), ['analisiSu' => 'server'], $this->token);
        self::assertSame('server', $p['analisiSu']);
        self::assertCount(1, array_filter($this->queue()->getSent(), static fn ($e) => $e->getMessage() instanceof AnalizzaDocumentoPraticaMessage && $e->getMessage()->documentoId === $altro));
        $this->request('POST', $this->url("/pratiche/{$r['id']}/risultati"), ['documentoId' => $altro, 'risposta' => '{}'], $this->token);
        $this->assertStatus(409);
        $this->request('PATCH', $this->url("/pratiche/{$r['id']}"), ['analisiSu' => 'luna'], $this->token);
        $this->assertStatus(422);
    }

    public function testEvaluationAndAnonymizedExportFromReviewedPratica(): void
    {
        $scontrino = $this->documento('casa', 'scontrino.png', self::SCONTRINO."\nCliente Mario Rossi", '2025-03-12');
        $visita = $this->documento('casa', 'visita.png', self::VISITA, '2025-06-05');
        $p = $this->avvia([$this->id($this->mario)]);
        $this->esegui($p['id']);
        foreach ($this->dettaglio($p['id'])['voci'] as $v) {
            $azione = $v['documento']['id'] === $scontrino ? 'conferma' : 'escludi';
            $this->request('POST', $this->url("/pratiche/{$p['id']}/voci/{$v['id']}/$azione"), token: $this->token);
            $this->assertStatus(200);
        }
        $app = new Application(static::$kernel);

        $valuta = new CommandTester($app->find('app:pratiche:valuta'));
        $valuta->execute(['--pratica' => [$p['id']]]);
        $out = $valuta->getDisplay();
        self::assertSame(0, $valuta->getStatusCode(), $out);
        self::assertStringContainsString('Analisi su 2 documenti', $out);
        self::assertMatchesRegularExpression('/spesa_sanitaria\s+1\s+2\s+1\s+1\s+0\s+50%\s+100%/', $out);

        $file = tempnam(sys_get_temp_dir(), 'esempi');
        $esporta = new CommandTester($app->find('app:pratiche:esporta-esempi'));
        $esporta->execute(['--pratica' => [$p['id']], '--uscita' => $file]);
        self::assertSame(0, $esporta->getStatusCode(), $esporta->getDisplay());
        $righe = array_map(static fn ($r) => json_decode($r, true), array_filter(explode("\n", (string) file_get_contents($file))));
        unlink($file);
        self::assertCount(2, $righe);
        $tutto = json_encode($righe, \JSON_UNESCAPED_UNICODE);
        foreach (['Mario', 'Rossi', 'scontrino.png', '14,40'] as $vero) {
            self::assertStringNotContainsString($vero, $tutto, $vero);
        }
        $positivo = array_values(array_filter($righe, static fn ($r) => str_contains($r['messages'][2]['content'], 'spesa_sanitaria')))[0];
        self::assertSame(['system', 'user', 'assistant'], array_column($positivo['messages'], 'role'));
        $risposta = json_decode($positivo['messages'][2]['content'], true)['voci'][0];
        // L'importo della risposta è quello (finto) scritto nel testo, la data come nel testo.
        self::assertStringContainsString($risposta['importo'], $positivo['messages'][1]['content']);
        self::assertSame(['spesa_sanitaria', 'farmaci', '12/03/2025'], [$risposta['requisito'], $risposta['tipo'], $risposta['data']]);
        $negativo = array_values(array_filter($righe, static fn ($r) => !str_contains($r['messages'][2]['content'], 'spesa_sanitaria')))[0];
        self::assertSame('{"voci":[]}', $negativo['messages'][2]['content']);
    }

    private function avvia(array $persone = []): array
    {
        $r = $this->request('POST', $this->url('/pratiche'), ['modello' => '730', 'anno' => 2025, 'persone' => $persone ?: [$this->id($this->mario), $this->id($this->luca)]], $this->token);
        $this->assertStatus(201);

        return $r;
    }

    private function dettaglio(string $id, ?string $token = null): array
    {
        $r = $this->request('GET', $this->url("/pratiche/$id"), token: $token ?? $this->token);
        $this->assertStatus(200);

        return $r;
    }

    /** Esegue i messaggi di analisi in coda (con le regole, senza AI). */
    private function esegui(string $praticaId): void
    {
        $handler = $this->handler();
        foreach ($this->queue()->getSent() as $envelope) {
            $m = $envelope->getMessage();
            if ($m instanceof AnalizzaDocumentoPraticaMessage && $m->praticaId === $praticaId) {
                $handler($m);
            }
        }
        $this->queue()->reset();
        $this->em()->clear();
    }

    private function handler(): AnalizzaDocumentoPraticaHandler
    {
        $c = static::getContainer();

        return new AnalizzaDocumentoPraticaHandler(
            $c->get(EntityManagerInterface::class),
            $c->get(DocumentService::class),
            $c->get(ModelliPratica::class),
            new EstrattoreVoci(new AiVociSuggeritore(new MockHttpClient(), 'http://ollama.test', '', 6000), new NullLogger()),
            $c->get(PraticaCifratura::class),
            $c->get(PraticaRepository::class),
            $c->get(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    /** @param list<MembroFamiglia> $membri */
    private function documento(string $categoria, string $nome, string $testo, ?string $data = null, array $membri = []): string
    {
        $campi = ['categoriaId' => (string) $this->categoria($categoria)->getId()];
        if (null !== $data) {
            $campi['dataDocumento'] = $data;
        }
        if ([] !== $membri) {
            $campi['membriIds'] = array_map(fn (MembroFamiglia $m) => $this->id($m), $membri);
        }
        $doc = $this->upload($this->tid(), $this->token, $this->fixture('ocr_fattura.png').$nome, $nome, $campi);
        $this->assertStatus(201);
        $entity = $this->em()->find(Documento::class, Uuid::fromString($doc['id']));
        static::getContainer()->get(DocumentService::class)->storeOcrText($entity, $testo);
        $this->em()->flush();
        $this->queue()->reset();

        return $doc['id'];
    }

    private function persona(string $nome, string $cognome): MembroFamiglia
    {
        $a = new Anagrafica();
        $a->setNome($nome);
        $a->setCognome($cognome);
        $this->em()->persist($a);
        $m = new MembroFamiglia($this->tenant, $this->owner, $a);
        $this->em()->persist($m);
        $this->em()->flush();

        return $m;
    }

    private function id(MembroFamiglia $m): string
    {
        return $m->getId()->toRfc4122();
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
