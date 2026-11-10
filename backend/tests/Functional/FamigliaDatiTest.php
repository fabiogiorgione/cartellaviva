<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Shared\Message\PuliziaPeriodicaMessage;
use App\Shared\MessageHandler\PuliziaPeriodicaHandler;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use League\Flysystem\FilesystemOperator;

/** Impostazioni "Dati" della famiglia: modalità avanzata, esportazione, eliminazione con ripensamento. */
final class FamigliaDatiTest extends ApiTestCase
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

    public function testAdvancedModeGatesCustomRolesAndCannotBeTurnedOffWhileInUse(): void
    {
        $token = $this->login($this->owner);
        $rolesUri = "/api/tenants/{$this->tid()}/roles";

        $this->request('POST', $rolesUri, ['name' => 'Nonni', 'permissions' => ['documento:read']], $token);
        $this->assertStatus(409);

        $this->patch($this->tenantUri(), ['modalitaAvanzata' => true], $token);
        $this->assertStatus(200);
        $nonni = $this->request('POST', $rolesUri, ['name' => 'Nonni', 'permissions' => ['documento:read']], $token);
        $this->assertStatus(201);

        $nonna = $this->addMember($this->createUser('nonna@example.com'), $this->tenant, Role::VIEWER);
        $this->patch("/api/tenants/{$this->tid()}/members/{$nonna->getId()}", ['roleId' => $nonni['id']], $token);
        $this->assertStatus(200);

        $this->patch($this->tenantUri(), ['modalitaAvanzata' => false], $token);
        $this->assertStatus(409);

        $this->patch("/api/tenants/{$this->tid()}/members/{$nonna->getId()}", ['roleId' => (string) $this->systemRole(Role::VIEWER)->getId()], $token);
        $this->patch($this->tenantUri(), ['modalitaAvanzata' => false], $token);
        $this->assertStatus(200);

        // Spenta: il ruolo personalizzato non si vede e non si assegna più.
        self::assertNotContains('Nonni', array_column($this->request('GET', $rolesUri, token: $token), 'name'));
        $this->patch("/api/tenants/{$this->tid()}/members/{$nonna->getId()}", ['roleId' => $nonni['id']], $token);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
    }

    public function testExportIsOwnerOnlySingleUseAndContainsDecryptedFilesAndIndex(): void
    {
        $token = $this->login($this->owner);
        $png = $this->fixture('ocr_fattura.png');
        $this->upload($this->tid(), $token, $png, 'referto.png', ['categoriaId' => (string) $this->categoria('salute')->getId(), 'titolo' => '=Referto']);
        $this->assertStatus(201);

        $member = $this->createUser('membro@example.com');
        $this->addMember($member, $this->tenant, Role::MEMBER);
        $this->request('POST', "/api/tenants/{$this->tid()}/esportazioni", [], $this->login($member));
        $this->assertStatus(403);

        $export = $this->request('POST', "/api/tenants/{$this->tid()}/esportazioni", [], $token);
        $this->assertStatus(201);
        self::assertStringStartsWith('/api/esportazioni/', $export['downloadPath']);

        $this->client->request('GET', $export['downloadPath']);
        $this->assertStatus(200);
        $zipBytes = (string) $this->client->getInternalResponse()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'fv-zip-');
        file_put_contents($path, $zipBytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = $zip->getNameIndex($i);
        }
        $pngName = current(array_filter($names, static fn ($n) => str_ends_with($n, '.png')));
        self::assertStringStartsWith('Salute/', $pngName);
        self::assertSame($png, $zip->getFromName($pngName), 'il documento cifrato esce decifrato');
        $indice = (string) $zip->getFromName('indice.csv');
        self::assertStringStartsWith("\u{FEFF}Categoria;Titolo", $indice);
        self::assertStringContainsString("'=Referto", $indice, 'niente formule eseguibili in Excel');
        $zip->close();
        @unlink($path);

        // Monouso.
        $this->client->request('GET', $export['downloadPath']);
        $this->assertStatus(410);
        self::assertCount(1, $this->auditRows('tenant.export_downloaded', 'success'));
    }

    public function testDeletionNeedsNameAnd2faThenReadOnlyCancelableAndFinallyPurged(): void
    {
        $token = $this->login($this->owner);
        $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'bolletta.png', ['categoriaId' => (string) $this->categoria('bollette')->getId()]);
        $this->assertStatus(201);
        $member = $this->createUser('membro@example.com');
        $this->addMember($member, $this->tenant, Role::MEMBER);

        // Senza 2FA attiva non si può.
        $this->request('POST', $this->deletionUri(), ['conferma' => 'Famiglia Rossi', 'codice' => '123456'], $token);
        $this->assertStatus(409);

        [$secret, $token, $backupCodes] = $this->enableTwoFactor($this->owner);
        $this->request('POST', $this->deletionUri(), ['conferma' => 'Famiglia Bianchi', 'codice' => $this->totp()->currentCode($secret)], $token);
        $this->assertStatus(422);
        $this->request('POST', $this->deletionUri(), ['conferma' => 'famiglia rossi', 'codice' => $this->totp()->currentCode($secret, 1)], $this->login($member));
        $this->assertStatus(403);

        $body = $this->request('POST', $this->deletionUri(), ['conferma' => 'famiglia rossi', 'codice' => $this->totp()->currentCode($secret, 1)], $token);
        $this->assertStatus(200);
        self::assertNotNull($body['eliminazioneProgrammataIl']);
        self::assertEmailCount(2);

        // Sola lettura: si legge, ma non si carica né si modifica.
        $tenant = $this->request('GET', $this->tenantUri(), token: $token);
        $this->assertStatus(200);
        self::assertTrue($tenant['solaLettura'] ?? null, json_encode($tenant));
        $this->request('GET', "/api/tenants/{$this->tid()}/documenti", token: $token);
        $this->assertStatus(200);
        $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'altro.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->assertStatus(403);

        // Annulla e riprogramma.
        $this->request('DELETE', $this->deletionUri(), token: $token);
        $this->assertStatus(204);
        self::assertFalse($this->request('GET', $this->tenantUri(), token: $token)['solaLettura']);
        $this->request('POST', $this->deletionUri(), ['conferma' => 'Famiglia Rossi', 'codice' => $backupCodes[0]], $token);
        $this->assertStatus(200);

        // Prima della scadenza il job non tocca nulla; dopo elimina tutto.
        $this->runCleanup();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = ?', [$this->tid()]));
        $this->em()->getConnection()->executeStatement("UPDATE tenant SET eliminazione_programmata_il = NOW() - INTERVAL '1 minute' WHERE id = ?", [$this->tid()]);
        $result = $this->runCleanup();
        self::assertSame(1, $result['famiglie_eliminate']);

        $db = $this->em()->getConnection();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = ?', [$this->tid()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM documento WHERE tenant_id = ?', [$this->tid()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM membership WHERE tenant_id = ?', [$this->tid()]));
        self::assertFalse($this->storage()->directoryExists($this->tid()));
        self::assertCount(1, $this->auditRows('tenant.purged', 'success'));
        // Gli utenti restano (possono avere altre famiglie).
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['membro@example.com']));
    }

    /** Registro #9 (decisione 2026-10-09): un'eliminazione decisa dal portale la annulla solo il portale. */
    public function testDeletionScheduledFromPortalCanBeCancelledOnlyFromPortal(): void
    {
        $admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $this->request('DELETE', "/api/admin/tenants/{$this->tid()}", ['motivo' => 'Segnalazione di abuso.'], $this->login($admin));
        $this->assertStatus(202);
        $token = $this->login($this->owner);
        self::assertTrue($this->request('GET', $this->tenantUri(), token: $token)['eliminazioneDalPortale']);

        $this->request('DELETE', $this->deletionUri(), token: $token);
        $this->assertStatus(409);
        self::assertTrue($this->request('GET', $this->tenantUri(), token: $token)['solaLettura']);

        $this->request('POST', "/api/admin/tenants/{$this->tid()}/annulla-eliminazione", token: $this->login($admin));
        $this->assertStatus(200);
        $tenant = $this->request('GET', $this->tenantUri(), token: $token);
        self::assertSame([false, false], [$tenant['solaLettura'], $tenant['eliminazioneDalPortale']]);
    }

    /**
     * Un'eliminazione che fallisce (qui forzata) non deve bloccare le
     * altre famiglie né il resto della pulizia, e non va contata come fatta.
     */
    public function testFailedPurgeDoesNotBlockTheOthers(): void
    {
        $db = $this->em()->getConnection();
        $bloccata = $this->createTenant('Famiglia Bloccata');
        $altra = $this->createTenant('Famiglia Verdi');
        foreach ([[$bloccata, '2 hours'], [$altra, '1 hour']] as [$t, $quanto]) {
            $db->executeStatement("UPDATE tenant SET eliminazione_programmata_il = NOW() - INTERVAL '$quanto' WHERE id = ?", [(string) $t->getId()]);
        }
        $db->executeStatement(sprintf(<<<'SQL'
            CREATE FUNCTION test_blocca_eliminazione() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'eliminazione bloccata dal test'; END $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_blocca BEFORE DELETE ON tenant FOR EACH ROW WHEN (OLD.id = '%s') EXECUTE FUNCTION test_blocca_eliminazione();
            SQL, $bloccata->getId()->toRfc4122()));

        $result = $this->runCleanup();

        self::assertSame(1, $result['famiglie_eliminate'], 'solo quella riuscita');
        self::assertArrayHasKey('emergenze_concesse', $result, 'la pulizia arriva in fondo');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = ?', [(string) $bloccata->getId()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = ?', [(string) $altra->getId()]));
    }

    /** @return array<string, int> */
    private function runCleanup(): array
    {
        $result = static::getContainer()->get(PuliziaPeriodicaHandler::class)(new PuliziaPeriodicaMessage());
        $this->em()->clear();

        return $result;
    }

    /** @return array{0: string, 1: string, 2: list<string>} segreto TOTP, nuovo JWT (dopo il login con 2FA), codici di recupero */
    private function enableTwoFactor(User $user): array
    {
        $token = $this->login($user);
        $setup = $this->request('POST', '/api/me/2fa/setup', [], $token);
        $enabled = $this->request('POST', '/api/me/2fa/enable', ['code' => $this->totp()->currentCode($setup['secret'], -1)], $token);
        $this->assertStatus(200);
        $step1 = $this->request('POST', '/api/auth/login', ['email' => $user->getEmail(), 'password' => self::PASSWORD]);
        $tokens = $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $this->totp()->currentCode($setup['secret'])]);

        return [$setup['secret'], $tokens['token'], $enabled['backupCodes']];
    }

    private function storage(): FilesystemOperator
    {
        return static::getContainer()->get('documents.storage');
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }

    private function tenantUri(): string
    {
        return "/api/tenants/{$this->tid()}";
    }

    private function deletionUri(): string
    {
        return "/api/tenants/{$this->tid()}/eliminazione";
    }
}
