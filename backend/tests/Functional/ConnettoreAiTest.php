<?php

namespace App\Tests\Functional;

use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\User;
use App\ConnettoreAi\LavoriAi;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Pratiche\Message\AnalizzaDocumentoPraticaMessage;
use App\Pratiche\MessageHandler\AnalizzaDocumentoPraticaHandler;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Connettore AI sul Mac (docs/connettore-ai.md): abbinamento con il device
 * grant, firewall separato, coda dei lavori con lease, risultato ricontrollato
 * dal server, errori, ritorno al server, consenso dei cifrati, revoca.
 */
final class ConnettoreAiTest extends ApiTestCase
{
    private const SCONTRINO = "FARMACIA CENTRALE SNC\nScontrino parlante\nFarmaco 12,40\nTOTALE EURO 14,40\nPagamento elettronico\n12/03/2025 10:41";
    private const MODELLO = 'qwen3:4b-instruct-2507-q4_K_M';

    private Tenant $tenant;
    private User $owner;
    private string $token;
    private MembroFamiglia $mario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
        $this->token = $this->login($this->owner);
        $a = new Anagrafica();
        $a->setNome('Mario');
        $a->setCognome('Rossi');
        $this->em()->persist($a);
        $this->mario = new MembroFamiglia($this->tenant, $this->owner, $a);
        $this->em()->persist($this->mario);
        $this->em()->flush();
    }

    public function testDeviceFlowFromRequestToTokens(): void
    {
        $this->request('POST', '/api/ai-connector-plugin/oauth/device', ['client_id' => 'altro', 'nome' => 'Mac']);
        $this->assertStatus(401);
        $d = $this->request('POST', '/api/ai-connector-plugin/oauth/device', ['client_id' => 'cartellaviva-mac', 'nome' => 'MacBook di Mario']);
        $this->assertStatus(200);
        self::assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/', $d['user_code']);
        self::assertSame([600, 5], [$d['expires_in'], $d['interval']]);
        self::assertStringEndsWith('/collega-mac?codice='.$d['user_code'], $d['verification_uri_complete']);

        $poll = fn () => $this->request('POST', '/api/ai-connector-plugin/oauth/token', ['client_id' => 'cartellaviva-mac', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $d['device_code']]);
        self::assertSame('authorization_pending', $poll()['error']);
        $this->assertStatus(400);
        // Interroga di nuovo subito: deve rallentare.
        self::assertSame('slow_down', $poll()['error']);

        // Un membro senza il permesso non approva; il proprietario vede la richiesta e approva (codice anche minuscolo).
        $membro = $this->createUser('membro@example.com');
        $this->addMember($membro, $this->tenant, 'member');
        $this->request('POST', $this->url('/connettori-ai/approva'), ['userCode' => $d['user_code']], $this->login($membro));
        $this->assertStatus(403);
        $vista = $this->request('GET', $this->url('/connettori-ai/richiesta?codice='.strtolower($d['user_code'])), token: $this->token);
        self::assertSame('MacBook di Mario', $vista['nome']);
        $this->request('POST', $this->url('/connettori-ai/approva'), ['userCode' => strtolower(str_replace('-', ' ', $d['user_code'])), 'nome' => 'Mac di casa'], $this->token);
        $this->assertStatus(200);

        $this->em()->getConnection()->executeStatement('UPDATE connettore_ai_richiesta SET ultimo_poll_il = NULL');
        $t = $poll();
        $this->assertStatus(200);
        self::assertSame(['Bearer', 3600, 'ai-connector'], [$t['token_type'], $t['expires_in'], $t['scope']]);
        // Il codice vale una volta sola.
        self::assertSame('invalid_grant', $poll()['error']);
        // A database solo gli hash.
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM connettore_ai WHERE access_token_hash = :t OR refresh_token_hash = :r', ['t' => $t['access_token'], 'r' => $t['refresh_token']]));
        $elenco = $this->request('GET', $this->url('/connettori-ai'), token: $this->token)['connettori'];
        self::assertSame([['Mac di casa', 'Owner'], 1], [[$elenco[0]['nome'], $elenco[0]['approvatoDa'] ? 'Owner' : null], \count($elenco)]);
        self::assertCount(1, $this->auditRows('connettore_ai.collegato'));

        // Rifiutato e scaduto.
        $d2 = $this->request('POST', '/api/ai-connector-plugin/oauth/device', ['client_id' => 'cartellaviva-mac', 'nome' => 'Altro']);
        $this->request('POST', $this->url('/connettori-ai/nega'), ['userCode' => $d2['user_code']], $this->token);
        self::assertSame('access_denied', $this->request('POST', '/api/ai-connector-plugin/oauth/token', ['client_id' => 'cartellaviva-mac', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $d2['device_code']])['error']);
        $d3 = $this->request('POST', '/api/ai-connector-plugin/oauth/device', ['client_id' => 'cartellaviva-mac', 'nome' => 'Altro']);
        $this->em()->getConnection()->executeStatement("UPDATE connettore_ai_richiesta SET scade_il = NOW() - INTERVAL '1 minute' WHERE user_code = :c", ['c' => $d3['user_code']]);
        self::assertSame('expired_token', $this->request('POST', '/api/ai-connector-plugin/oauth/token', ['client_id' => 'cartellaviva-mac', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $d3['device_code']])['error']);
        $this->request('POST', $this->url('/connettori-ai/approva'), ['userCode' => $d3['user_code']], $this->token);
        $this->assertStatus(404);
    }

    public function testApprovalNeedsTheSecondFactorWhenEnabled(): void
    {
        $setup = $this->request('POST', '/api/me/2fa/setup', [], $this->token);
        $this->request('POST', '/api/me/2fa/enable', ['code' => $this->totp()->currentCode($setup['secret'], -1)], $this->token);
        $step1 = $this->request('POST', '/api/auth/login', ['email' => 'owner@example.com', 'password' => self::PASSWORD]);
        $token = $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $this->totp()->currentCode($setup['secret'])])['token'];
        $d = $this->request('POST', '/api/ai-connector-plugin/oauth/device', ['client_id' => 'cartellaviva-mac', 'nome' => 'Mac']);
        $this->request('POST', $this->url('/connettori-ai/approva'), ['userCode' => $d['user_code']], $token);
        $this->assertStatus(403);
        $this->request('POST', $this->url('/connettori-ai/approva'), ['userCode' => $d['user_code'], 'codice2fa' => '000000'], $token);
        $this->assertStatus(422);
        $this->request('POST', $this->url('/connettori-ai/approva'), ['userCode' => $d['user_code'], 'codice2fa' => $this->totp()->currentCode($setup['secret'], 1)], $token);
        $this->assertStatus(200);
    }

    public function testTokensAreSeparateFromUserTokensAndRefreshIsSingleUse(): void
    {
        [$access, $refresh] = $this->collega();
        // Il token del connettore non apre l'API degli utenti, e il JWT di un utente non apre quella del connettore.
        $this->request('GET', $this->url('/documenti'), token: $access);
        $this->assertStatus(401);
        $this->request('GET', '/api/me', token: $access);
        $this->assertStatus(401);
        $this->request('PUT', '/api/ai-connector-plugin/me', ['versione' => '1.0.0'], $this->token);
        $this->assertStatus(401);
        $me = $this->request('PUT', '/api/ai-connector-plugin/me', ['versione' => '1.0.0', 'motore' => 'ollama', 'modello' => self::MODELLO, 'contesto' => 8192, 'memoriaGb' => 16], $access);
        $this->assertStatus(200);
        self::assertSame(['Famiglia Rossi', [self::MODELLO], false], [$me['famiglia']['nome'], $me['modelliVerificati'], $me['sperimentaliAmmessi']]);

        $nuovi = $this->rinnova($refresh);
        $this->assertStatus(200);
        self::assertNotSame($refresh, $nuovi['refresh_token']);
        $this->request('PUT', '/api/ai-connector-plugin/me', [], $access);
        $this->assertStatus(401);
        $this->request('PUT', '/api/ai-connector-plugin/me', [], $nuovi['access_token']);
        $this->assertStatus(200);
        // Il vecchio refresh token torna: qualcuno l'ha copiato, il connettore si scollega.
        self::assertSame('invalid_grant', $this->rinnova($refresh)['error']);
        $this->request('PUT', '/api/ai-connector-plugin/me', [], $nuovi['access_token']);
        $this->assertStatus(401);
        self::assertSame('invalid_grant', $this->rinnova($nuovi['refresh_token'])['error']);
        self::assertCount(1, $this->auditRows('connettore_ai.scollegato'));
    }

    public function testDocumentsGoToTheOnlineMacWhichReturnsOnlyTheModelValues(): void
    {
        $this->documento('casa', 'scontrino.png', self::SCONTRINO);
        // Nessun Mac: si analizza sul server come sempre.
        $p730 = $this->avvia('730', 2025);
        self::assertSame(0, $this->lavoriInCoda());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pratica_analisi'));

        [$access] = $this->collega();
        $this->request('PUT', '/api/ai-connector-plugin/me', ['motore' => 'ollama', 'modello' => self::MODELLO], $access);
        $this->documento('casa', 'scontrino2.png', str_replace(['14,40', '12/03/2025'], ['22,00', '15/04/2025'], self::SCONTRINO));
        $this->request('POST', $this->url("/pratiche/{$p730}/analizza"), [], $this->token);
        $this->esegui();
        // Il Mac è online: il documento diventa un lavoro, il server non lo analizza.
        self::assertSame(1, $this->lavoriInCoda());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pratica_analisi'));

        $lavori = $this->request('GET', '/api/ai-connector-plugin/lavori?limite=3&caratteri=6000', token: $access);
        $this->assertStatus(200);
        self::assertCount(1, $lavori['lavori']);
        $l = $lavori['lavori'][0];
        self::assertSame(['pratica_voci', '730 2025'], [$l['tipo'], $l['pratica']]);
        self::assertStringContainsString('22,00', $l['prompt']);
        self::assertIsArray(json_decode($l['schema'], true));
        // Subito un'altra richiesta: troppo presto.
        $this->request('GET', '/api/ai-connector-plugin/lavori', token: $access);
        $this->assertStatus(429);

        // Un modello non verificato non vale.
        $voci = json_encode(['voci' => [['requisito' => 'spesa_sanitaria', 'tipo' => 'farmaci', 'importo' => '99,99', 'data' => '15/04/2025', 'emittente' => 'Farmacia Centrale', 'persona' => '', 'tracciabile' => 'si', 'descrizione' => '']]]);
        $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/risultato", ['risposta' => $voci, 'modello' => 'llama3:70b'], $access);
        $this->assertStatus(409);
        // L'importo inventato dal modello (99,99) si corregge sul testo.
        $r = $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/risultato", ['risposta' => $voci, 'modello' => self::MODELLO, 'durataMs' => 4210], $access);
        $this->assertStatus(200);
        self::assertSame(['voci' => 1], $r);
        $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/risultato", ['risposta' => $voci, 'modello' => self::MODELLO], $access);
        $this->assertStatus(409);

        $voce = array_values(array_filter($this->request('GET', $this->url("/pratiche/{$p730}"), token: $this->token)['voci'], static fn ($v) => '22.00' === ($v['valori']['importo'] ?? null)));
        self::assertCount(1, $voce);
        self::assertSame('ai_connettore', $voce[0]['origine']);
        $analisi = $this->em()->getConnection()->fetchAssociative('SELECT metodo, modello, durata_ms, connettore_ai_id FROM pratica_analisi WHERE modello IS NOT NULL');
        self::assertSame(['mac:qwen3-4b', self::MODELLO, 4210], [$analisi['metodo'], $analisi['modello'], $analisi['durata_ms']]);
        self::assertNotNull($analisi['connettore_ai_id']);
        self::assertSame(0, $this->lavoriInCoda());
        $mac = $this->request('GET', $this->url('/connettori-ai'), token: $this->token)['connettori'][0];
        self::assertSame([1, 4210, true], [$mac['lavoriFatti'], $mac['durataMediaMs'], $mac['online']]);
    }

    public function testLeaseErrorsAndReturnToTheServer(): void
    {
        [$access] = $this->collega();
        $this->request('PUT', '/api/ai-connector-plugin/me', [], $access);
        $this->documento('casa', 'scontrino.png', self::SCONTRINO);
        $this->avvia('730', 2025);
        self::assertSame(1, $this->lavoriInCoda());

        // Lease scaduto: il risultato non vale più, il lavoro torna in attesa.
        $l = $this->request('GET', '/api/ai-connector-plugin/lavori', token: $access)['lavori'][0];
        $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/proroga", [], $access);
        $this->assertStatus(200);
        $this->em()->getConnection()->executeStatement("UPDATE lavoro_ai SET scade_il = NOW() - INTERVAL '1 minute'");
        $this->em()->clear();
        $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/risultato", ['risposta' => '{"voci":[]}', 'modello' => self::MODELLO], $access);
        $this->assertStatus(409);
        $this->lavoriAi()->riprendi();
        self::assertSame(['in_attesa', 1], $this->statoLavoro());

        // Due errori: va al server.
        $this->em()->getConnection()->executeStatement('UPDATE lavoro_ai SET tentativi = 0');
        $this->em()->clear();
        $this->cache()->clear();
        $l = $this->request('GET', '/api/ai-connector-plugin/lavori', token: $access)['lavori'][0];
        $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/errore", ['codice' => 'sconosciuto'], $access);
        $this->assertStatus(422);
        $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/errore", ['codice' => 'timeout'], $access);
        self::assertSame(['in_attesa', 1], $this->statoLavoro());
        $this->cache()->clear();
        $l = $this->request('GET', '/api/ai-connector-plugin/lavori', token: $access)['lavori'][0];
        $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/errore", ['codice' => 'memoria'], $access);
        $this->assertStatus(200);
        $inviati = $this->messaggi();
        self::assertSame(0, $this->lavoriInCoda());
        self::assertCount(1, $inviati);
        self::assertTrue($inviati[0]->forzaServer);
        // Con forzaServer il server lo analizza anche con il Mac online.
        $this->handler()($inviati[0]);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pratica_analisi'));
    }

    public function testMacOfflineTooLongAndRevocation(): void
    {
        [$access] = $this->collega();
        $this->request('PUT', '/api/ai-connector-plugin/me', [], $access);
        $this->documento('casa', 'scontrino.png', self::SCONTRINO);
        $this->avvia('730', 2025);
        self::assertSame(1, $this->lavoriInCoda());

        // Il Mac sparisce: dopo l'attesa massima il lavoro torna al server.
        $this->em()->getConnection()->executeStatement("UPDATE connettore_ai SET ultimo_contatto = NOW() - INTERVAL '5 minutes'");
        $this->em()->clear();
        $this->lavoriAi()->riprendi();
        self::assertSame(1, $this->lavoriInCoda());
        $this->em()->getConnection()->executeStatement("UPDATE lavoro_ai SET creato_il = NOW() - INTERVAL '11 minutes'");
        $this->em()->clear();
        $this->lavoriAi()->riprendi();
        self::assertSame(0, $this->lavoriInCoda());
        self::assertTrue($this->messaggi()[0]->forzaServer);

        // Scollegato dall'app: il token non vale più.
        $id = $this->request('GET', $this->url('/connettori-ai'), token: $this->token)['connettori'][0]['id'];
        $this->request('DELETE', $this->url("/connettori-ai/$id"), token: $this->token);
        $this->assertStatus(204);
        $this->request('PUT', '/api/ai-connector-plugin/me', [], $access);
        $this->assertStatus(401);
        self::assertSame([], $this->request('GET', $this->url('/connettori-ai'), token: $this->token)['connettori']);
    }

    public function testEncryptedDocumentsNeedTheConsentForDevices(): void
    {
        [$access] = $this->collega();
        $this->request('PUT', '/api/ai-connector-plugin/me', [], $access);
        $doc = $this->documento('salute', 'referto.png', "Fattura sanitaria\nVisita specialistica\nTotale 80,00 €\n05/06/2025");
        self::assertTrue($this->em()->find(Documento::class, Uuid::fromString($doc))->isCifrato());
        $p = $this->avvia('730', 2025);
        $this->request('POST', $this->url("/pratiche/$p/consenso-cifrati"), ['consenso' => true], $this->token);
        $this->esegui();
        // Consenso solo per il server: il cifrato non va al Mac.
        self::assertSame(0, $this->lavoriInCoda());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pratica_analisi'));

        $this->em()->getConnection()->executeStatement('DELETE FROM pratica_analisi');
        $dettaglio = $this->request('POST', $this->url("/pratiche/$p/consenso-cifrati"), ['consenso' => true, 'dispositivi' => true], $this->token);
        self::assertTrue($dettaglio['consensoCifrati']['dispositivi']);
        $this->esegui();
        self::assertSame(1, $this->lavoriInCoda());
        // Revoca del consenso per i dispositivi: il lavoro torna al server.
        $this->request('POST', $this->url("/pratiche/$p/consenso-cifrati"), ['consenso' => true, 'dispositivi' => false], $this->token);
        self::assertSame(0, $this->lavoriInCoda());
    }

    /** @return array{0: string, 1: string} token di accesso e refresh token di un Mac approvato dal proprietario */
    private function collega(): array
    {
        $d = $this->request('POST', '/api/ai-connector-plugin/oauth/device', ['client_id' => 'cartellaviva-mac', 'nome' => 'MacBook di Mario']);
        $this->request('POST', $this->url('/connettori-ai/approva'), ['userCode' => $d['user_code']], $this->token);
        $this->assertStatus(200);
        $t = $this->request('POST', '/api/ai-connector-plugin/oauth/token', ['client_id' => 'cartellaviva-mac', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $d['device_code']]);
        $this->assertStatus(200);

        return [$t['access_token'], $t['refresh_token']];
    }

    /** @return array<string, mixed> */
    private function rinnova(string $refresh): array
    {
        return (array) $this->request('POST', '/api/ai-connector-plugin/oauth/token', ['client_id' => 'cartellaviva-mac', 'grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    }

    private function avvia(string $modello, int $anno): string
    {
        $r = $this->request('POST', $this->url('/pratiche'), ['modello' => $modello, 'anno' => $anno, 'persone' => [$this->mario->getId()->toRfc4122()]], $this->token);
        $this->assertStatus(201);
        $this->esegui();

        return $r['id'];
    }

    /** Esegue i messaggi di analisi in coda (in memoria: si perdono alla richiesta successiva). */
    private function esegui(): void
    {
        foreach ($this->messaggi() as $m) {
            $this->handler()($m);
        }
        $this->queue()->reset();
        $this->em()->clear();
    }

    /** @return list<AnalizzaDocumentoPraticaMessage> */
    private function messaggi(): array
    {
        return array_values(array_filter(array_map(static fn ($e) => $e->getMessage(), $this->queue()->getSent()), static fn ($m) => $m instanceof AnalizzaDocumentoPraticaMessage));
    }

    private function handler(): AnalizzaDocumentoPraticaHandler
    {
        return static::getContainer()->get(AnalizzaDocumentoPraticaHandler::class);
    }

    private function lavoriAi(): LavoriAi
    {
        return static::getContainer()->get(LavoriAi::class);
    }

    private function cache(): \Psr\Cache\CacheItemPoolInterface
    {
        return static::getContainer()->get('cache.app');
    }

    private function lavoriInCoda(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM lavoro_ai');
    }

    /** @return array{0: string, 1: int} */
    private function statoLavoro(): array
    {
        $r = $this->em()->getConnection()->fetchAssociative('SELECT stato, tentativi FROM lavoro_ai');

        return [(string) $r['stato'], (int) $r['tentativi']];
    }

    private function documento(string $categoria, string $nome, string $testo): string
    {
        $doc = $this->upload((string) $this->tenant->getId(), $this->token, $this->fixture('ocr_fattura.png').$nome, $nome, ['categoriaId' => (string) $this->categoria($categoria)->getId()]);
        $this->assertStatus(201);
        $entity = $this->em()->find(Documento::class, Uuid::fromString($doc['id']));
        static::getContainer()->get(DocumentService::class)->storeOcrText($entity, $testo);
        $this->em()->flush();
        $this->queue()->reset();

        return $doc['id'];
    }

    private function url(string $percorso): string
    {
        return '/api/tenants/'.$this->tenant->getId().$percorso;
    }

    private function queue(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }
}
