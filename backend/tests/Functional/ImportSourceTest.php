<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Import\Entity\ImportSource;
use App\Import\ImportTokenVault;
use App\Import\Message\ImportJobMessage;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tests\Support\TestGoogleOAuth;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** Sorgenti di import: creazione, collegamento OAuth, richiesta di sincronizzazione, permessi. */
final class ImportSourceTest extends ApiTestCase
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

    public function testMissingOAuthConfigurationGivesExplicitErrorNot500(): void
    {
        $this->oauth()->configured = false;

        $body = $this->request('POST', $this->base(), ['provider' => 'google_drive', 'cartellaRadiceId' => 'cartella1', 'strategia' => 'cartelle'], $this->login($this->owner));

        $this->assertStatus(422);
        self::assertStringContainsString('Provider non configurato', (string) ($body['detail'] ?? ''));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM import_source'));
    }

    public function testInvalidInputIsRejected(): void
    {
        $this->request('POST', $this->base(), ['provider' => 'onedrive', 'cartellaRadiceId' => '../x', 'strategia' => 'boh'], $this->login($this->owner));

        $this->assertStatus(422);
    }

    public function testDriveFolderLinkIsAcceptedAndReducedToItsId(): void
    {
        $token = $this->login($this->owner);
        $links = [
            'https://drive.google.com/drive/folders/1AbC_dEf-GhIjKlMnOp?usp=sharing' => '1AbC_dEf-GhIjKlMnOp',
            'https://drive.google.com/drive/u/0/folders/1ZyXwVuTsRqPoNmLk' => '1ZyXwVuTsRqPoNmLk',
            'https://drive.google.com/open?id=1QwErTyUiOpAsDfGh' => '1QwErTyUiOpAsDfGh',
        ];
        foreach ($links as $link => $id) {
            $body = $this->request('POST', $this->base(), ['provider' => 'google_drive', 'cartellaRadiceId' => $link, 'strategia' => 'cartelle'], $token);
            $this->assertStatus(201);
            self::assertSame($id, $body['cartellaRadiceId']);
        }

        foreach (['https://evil.example/drive/folders/1AbCdEfGhIjKlMn', 'http://drive.google.com/drive/folders/1AbCdEfGhIjKlMn', 'https://drive.google.com/file/d/1AbCdEfGhIjKlMn/view'] as $bad) {
            $this->request('POST', $this->base(), ['provider' => 'google_drive', 'cartellaRadiceId' => $bad, 'strategia' => 'cartelle'], $token);
            $this->assertStatus(422);
        }
    }

    public function testMemberAndOtherTenantCannotManageImports(): void
    {
        $member = $this->createUser('membro@example.com');
        $this->addMember($member, $this->tenant, Role::MEMBER);
        $this->request('POST', $this->base(), ['provider' => 'google_drive', 'cartellaRadiceId' => 'c1', 'strategia' => 'cartelle'], $this->login($member));
        $this->assertStatus(403);

        $source = $this->createSource();
        $altro = $this->createUser('luca@example.com');
        $this->addMember($altro, $this->createTenant('Famiglia Bianchi'), Role::OWNER);
        $this->request('POST', $this->base()."/{$source['id']}/sync", token: $this->login($altro));
        $this->assertStatus(403);
    }

    public function testConnectFlowStoresOnlyEncryptedTokensAndStateIsSingleUse(): void
    {
        $source = $this->createSource();
        self::assertSame('non_configurato', $source['stato']);
        self::assertFalse($source['collegata']);

        $connect = $this->request('POST', $this->base()."/{$source['id']}/connect", token: $this->login($this->owner));
        $this->assertStatus(200);
        self::assertStringStartsWith('https://accounts.google.com/', $connect['authorizationUrl']);
        $state = $this->oauth()->lastState;
        self::assertNotNull($state);
        $stored = $this->em()->getConnection()->fetchOne('SELECT oauth_state_hash FROM import_source');
        self::assertSame(hash('sha256', $state), $stored, 'Nel DB solo l\'hash dello state');

        // Callback pubblica (nessun JWT).
        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $state, 'code' => TestGoogleOAuth::GOOD_CODE]));
        $this->assertStatus(302);
        self::assertStringContainsString('esito=ok', (string) $this->client->getResponse()->headers->get('Location'));

        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM import_source');
        self::assertSame('attivo', $row['stato']);
        self::assertNull($row['oauth_state_hash']);
        self::assertStringNotContainsString('refresh-token-segreto', base64_decode($row['oauth_refresh_token']));
        self::assertStringNotContainsString('access-token-1', base64_decode($row['oauth_access_token']));
        $entity = $this->em()->find(ImportSource::class, Uuid::fromString($source['id']));
        self::assertSame('refresh-token-segreto', static::getContainer()->get(ImportTokenVault::class)->refreshToken($entity));

        $detail = $this->request('GET', $this->base()."/{$source['id']}", token: $this->login($this->owner));
        self::assertTrue($detail['collegata']);
        self::assertSame('famiglia@gmail.com', $detail['accountEsterno']);
        self::assertArrayNotHasKey('oauthRefreshToken', $detail);

        // Lo stesso state non vale una seconda volta.
        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $state, 'code' => TestGoogleOAuth::GOOD_CODE]));
        self::assertStringContainsString('motivo=non_valido', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->auditRows('import.connected', 'success'));
    }

    // ---- Caratterizzazione (fase 0): rami non coperti ------------------------

    public function testExpiredStateDoesNotConnect(): void
    {
        $source = $this->createSource();
        $this->request('POST', $this->base()."/{$source['id']}/connect", token: $this->login($this->owner));
        $this->em()->getConnection()->executeStatement("UPDATE import_source SET oauth_state_scadenza = NOW() - INTERVAL '1 minute'");

        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $this->oauth()->lastState, 'code' => TestGoogleOAuth::GOOD_CODE]));
        self::assertStringContainsString('motivo=scaduto', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertFalse($this->request('GET', $this->base()."/{$source['id']}", token: $this->login($this->owner))['collegata']);
        self::assertCount(1, $this->auditRows('import.connect_failed', 'failure'));
    }

    public function testPermissionLostBeforeTheCallbackDoesNotConnect(): void
    {
        $source = $this->createSource();
        $this->request('POST', $this->base()."/{$source['id']}/connect", token: $this->login($this->owner));
        // Nel frattempo l'utente non è più proprietario (un altro proprietario resta).
        $this->addMember($this->createUser('nuovo@example.com'), $this->tenant, Role::OWNER);
        $m = $this->em()->getRepository(\App\Tenancy\Entity\Membership::class)->findOneBy(['user' => $this->owner->getId()]);
        $m->changeRole($this->systemRole(Role::MEMBER));
        $this->em()->flush();

        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $this->oauth()->lastState, 'code' => TestGoogleOAuth::GOOD_CODE]));
        self::assertStringContainsString('motivo=non_valido', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->auditRows('import.connect_failed', 'denied'));
    }

    public function testFamilyDeletionDisconnectsSourcesAndRevokesAccessAtGoogle(): void
    {
        $source = $this->createSource();
        $this->request('POST', $this->base()."/{$source['id']}/connect", token: $this->login($this->owner));
        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $this->oauth()->lastState, 'code' => TestGoogleOAuth::GOOD_CODE]));
        $admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);

        $this->request('DELETE', "/api/admin/tenants/{$this->tenant->getId()}", ['motivo' => 'Richiesta della famiglia.'], $this->login($admin));
        $this->assertStatus(202);

        self::assertSame(['refresh-token-segreto'], $this->oauth()->revoked);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT oauth_refresh_token, oauth_access_token FROM import_source');
        self::assertSame([null, null], [$row['oauth_refresh_token'], $row['oauth_access_token']], 'i token locali si cancellano');
        self::assertCount(1, $this->auditRows('import.disconnected'));
    }

    /** Registro #8: un collegamento avviato prima della cancellazione non si completa dopo. */
    public function testOAuthStartedBeforeDeletionCannotCompleteAfterIt(): void
    {
        $source = $this->createSource();
        $this->request('POST', $this->base()."/{$source['id']}/connect", token: $this->login($this->owner));
        $state = $this->oauth()->lastState;
        $admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $this->request('DELETE', "/api/admin/tenants/{$this->tenant->getId()}", ['motivo' => 'Richiesta della famiglia.'], $this->login($admin));
        $this->assertStatus(202);

        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $state, 'code' => TestGoogleOAuth::GOOD_CODE]));
        self::assertStringContainsString('motivo=non_valido', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT oauth_refresh_token FROM import_source'), 'nessun token salvato');
        $this->request('POST', $this->base()."/{$source['id']}/connect", token: $this->login($this->owner));
        $this->assertStatus(403);
    }

    public function testAppReturnGoesToConfiguredDeepLinkAndArbitraryTargetsAreRejected(): void
    {
        $source = $this->createSource();
        $token = $this->login($this->owner);

        $this->request('POST', $this->base()."/{$source['id']}/connect", ['ritorno' => 'https://evil.example/x'], $token);
        $this->assertStatus(422);

        $this->request('POST', $this->base()."/{$source['id']}/connect", ['ritorno' => 'app'], $token);
        $this->assertStatus(200);
        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $this->oauth()->lastState, 'code' => TestGoogleOAuth::GOOD_CODE]));
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('cartellaviva://import/esito?', $location);
        self::assertStringContainsString('esito=ok', $location);

        // Senza indicazione si torna al web, anche in caso di errore.
        $this->request('POST', $this->base()."/{$source['id']}/connect", token: $token);
        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $this->oauth()->lastState, 'error' => 'access_denied']));
        self::assertStringStartsWith('http://localhost:5000/import/esito?', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCallbackWithUnknownStateOrUserDenialDoesNotConnect(): void
    {
        $this->client->request('GET', '/api/import-sources/oauth/callback?state=inventato&code='.TestGoogleOAuth::GOOD_CODE);
        $this->assertStatus(302);
        self::assertStringContainsString('motivo=non_valido', (string) $this->client->getResponse()->headers->get('Location'));

        $source = $this->createSource();
        $this->request('POST', $this->base()."/{$source['id']}/connect", token: $this->login($this->owner));
        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $this->oauth()->lastState, 'error' => 'access_denied']));
        self::assertStringContainsString('motivo=annullato', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('non_configurato', $this->em()->getConnection()->fetchOne('SELECT stato FROM import_source'));
    }

    public function testSyncRequiresConnectionAndQueuesOneJob(): void
    {
        $source = $this->createSource();
        $token = $this->login($this->owner);

        $this->request('POST', $this->base()."/{$source['id']}/sync", token: $token);
        $this->assertStatus(409);

        $this->connect($source['id']);
        $body = $this->request('POST', $this->base()."/{$source['id']}/sync", token: $token);
        $this->assertStatus(202);
        self::assertTrue($body['sincronizzazioneInCorso']);
        $sent = array_values(array_filter($this->queue()->getSent(), static fn ($e) => $e->getMessage() instanceof ImportJobMessage));
        self::assertCount(1, $sent);
        self::assertSame($source['id'], $sent[0]->getMessage()->importSourceId);

        // Seconda richiesta mentre la prima è in corso.
        $this->request('POST', $this->base()."/{$source['id']}/sync", token: $token);
        $this->assertStatus(409);
    }

    public function testDeleteRemovesSourceAndTokens(): void
    {
        $source = $this->createSource();
        $this->connect($source['id']);

        $this->request('DELETE', $this->base()."/{$source['id']}", token: $this->login($this->owner));

        $this->assertStatus(204);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM import_source'));
    }

    /** @return array<string, mixed> */
    private function createSource(string $strategia = 'cartelle'): array
    {
        $body = $this->request('POST', $this->base(), ['provider' => 'google_drive', 'cartellaRadiceId' => 'radice', 'strategia' => $strategia], $this->login($this->owner));
        $this->assertStatus(201);

        return $body;
    }

    private function connect(string $sourceId): void
    {
        $this->request('POST', $this->base()."/$sourceId/connect", token: $this->login($this->owner));
        $this->client->request('GET', '/api/import-sources/oauth/callback?'.http_build_query(['state' => $this->oauth()->lastState, 'code' => TestGoogleOAuth::GOOD_CODE]));
    }

    private function base(): string
    {
        return "/api/tenants/{$this->tenant->getId()}/import-sources";
    }

    private function oauth(): TestGoogleOAuth
    {
        return static::getContainer()->get(TestGoogleOAuth::class);
    }

    private function queue(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }
}
