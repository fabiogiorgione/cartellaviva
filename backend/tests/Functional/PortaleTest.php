<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Account\TwoFactor\TotpService;
use App\Shared\Message\PuliziaPeriodicaMessage;
use App\Shared\MessageHandler\PuliziaPeriodicaHandler;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\Mime\Email;

/**
 * Portale (fase 4): ruoli di piattaforma, salute e da fare, utenti, recupero
 * della 2FA con verifica dell'identità e 72 ore, famiglie senza proprietario.
 */
final class PortaleTest extends ApiTestCase
{
    private User $admin;
    private User $supporto;
    private User $lettore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $this->supporto = $this->createUser('supporto@cartellaviva.local');
        $this->lettore = $this->createUser('lettura@cartellaviva.local');
        $adminToken = $this->login($this->admin);
        $this->request('PUT', "/api/admin/utenti/{$this->supporto->getId()}/ruolo-piattaforma", ['ruolo' => 'supporto'], $adminToken);
        $this->assertStatus(200);
        $this->request('PUT', "/api/admin/utenti/{$this->lettore->getId()}/ruolo-piattaforma", ['ruolo' => 'sola_lettura'], $adminToken);
    }

    public function testPlatformLevels(): void
    {
        $utente = $this->createUser('normale@example.com');
        $this->request('GET', '/api/admin/salute', token: $this->login($utente));
        $this->assertStatus(403);

        $lettore = $this->login($this->lettore);
        $this->request('GET', '/api/admin/salute', token: $lettore);
        $this->assertStatus(200);
        $this->request('GET', '/api/admin/audit_logs?outcome=denied', token: $lettore);
        $this->assertStatus(200);
        $this->request('GET', '/api/admin/tenants', token: $lettore);
        $this->assertStatus(403);
        self::assertSame('sola_lettura', $this->request('GET', '/api/me', token: $lettore)['platformRole']);

        $supporto = $this->login($this->supporto);
        $this->request('GET', '/api/admin/utenti?q=normale', token: $supporto);
        $this->assertStatus(200);
        $this->request('GET', '/api/admin/team', token: $supporto);
        $this->assertStatus(403);
        $this->request('PUT', "/api/admin/utenti/{$utente->getId()}/ruolo-piattaforma", ['ruolo' => 'supporto'], $supporto);
        $this->assertStatus(403);

        self::assertCount(3, $this->request('GET', '/api/admin/team', token: $this->login($this->admin)));
    }

    public function testHealthShowsTodoWithoutContent(): void
    {
        [$famiglia] = $this->famiglia('mario@example.com');
        $salute = $this->request('GET', '/api/admin/salute', token: $this->login($this->lettore));
        self::assertContains('backup', array_column($salute['daFare'], 'tipo'));
        self::assertSame(1, $salute['numeri']['famiglie']);
        self::assertArrayHasKey('worker', $salute['componenti']);
        self::assertFalse(isset($salute['componenti']['chiave']['improntaOk']) && false === $salute['componenti']['chiave']['improntaOk']);
    }

    public function testSupportBlocksAndUnblocksUsers(): void
    {
        [, $mario] = $this->famiglia('mario@example.com');
        $supporto = $this->login($this->supporto);
        $trovati = $this->request('GET', '/api/admin/utenti?q=mario', token: $supporto);
        self::assertSame('mario@example.com', $trovati[0]['email']);
        $dettaglio = $this->request('GET', "/api/admin/utenti/{$mario->getId()}", token: $supporto);
        self::assertSame('Famiglia Rossi', $dettaglio['appartenenze'][0]['tenant']);
        self::assertArrayNotHasKey('documenti', $dettaglio);

        $sessioneMario = $this->login($mario);
        $this->request('POST', "/api/admin/utenti/{$mario->getId()}/stato", ['attivo' => false, 'motivo' => 'segnalazione'], $supporto);
        $this->assertStatus(200);
        $this->request('POST', '/api/auth/login', ['email' => 'mario@example.com', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        // Anche la sessione già aperta si chiude subito.
        $this->request('GET', '/api/me', token: $sessioneMario);
        $this->assertStatus(401);
        $this->request('POST', "/api/admin/utenti/{$mario->getId()}/stato", ['attivo' => true], $supporto);
        $this->login($this->fresh($mario));
        // Il supporto non blocca il team del portale.
        $this->request('POST', "/api/admin/utenti/{$this->lettore->getId()}/stato", ['attivo' => false], $supporto);
        $this->assertStatus(409);
    }

    public function testTwoFactorRecoveryWithIdentityCheckAnd72Hours(): void
    {
        [, $mario] = $this->famiglia('mario@example.com');
        $giulia = $this->createUser('giulia@example.com');
        $this->addMember($giulia, $this->tenantByName('Famiglia Rossi'), Role::MEMBER);
        $secret = $this->enableTwoFactor($giulia);

        // Nessuna enumerazione: stessa risposta, nessuna email per account inesistenti.
        $this->mailer()->reset();
        $this->request('POST', '/api/auth/recupero', ['email' => 'nessuno@example.com']);
        $this->assertStatus(202);
        self::assertCount(0, $this->mailer()->getEvents()->getMessages());
        $this->request('POST', '/api/auth/recupero', ['email' => 'giulia@example.com', 'motivo' => 'telefono rubato']);
        $this->assertStatus(202);
        $token = $this->tokenDa($this->ultimaEmail(), 'conferma');
        $this->request('POST', '/api/auth/recupero/conferma', ['token' => $token]);
        $this->assertStatus(200);
        $this->request('POST', '/api/auth/recupero/conferma', ['token' => $token]);
        $this->assertStatus(410);

        $supporto = $this->login($this->supporto);
        $procedura = $this->request('GET', '/api/admin/procedure', token: $supporto)[0];
        self::assertSame(['recupero_2fa', 'identita_da_verificare', 'telefono rubato'], [$procedura['tipo'], $procedura['stato'], $procedura['motivo']]);
        $this->request('POST', "/api/admin/procedure/{$procedura['id']}/identita", ['esito' => 'verificata'], $supporto);
        $this->assertStatus(422);

        $this->mailer()->reset();
        $ok = $this->request('POST', "/api/admin/procedure/{$procedura['id']}/identita", ['esito' => 'verificata', 'documento' => 'Carta d\'identità …4821', 'come' => 'videochiamata'], $supporto);
        self::assertSame('in_attesa', $ok['stato']);
        $destinatari = array_map(static fn (Email $e) => $e->getTo()[0]->getAddress(), $this->mailer()->getEvents()->getMessages());
        self::assertEqualsCanonicalizing(['giulia@example.com', 'mario@example.com'], $destinatari, 'avviso a lei e ai proprietari');

        // Prima delle 72 ore il job non tocca nulla.
        $this->runJob();
        self::assertTrue($this->fresh($giulia)->isTwoFactorEnabled());

        // Mario annulla dal link: "non sono d'accordo".
        $annulla = $this->tokenDa($this->mailer()->getEvents()->getMessages()[1], 'annulla');
        $this->request('POST', '/api/procedure/annulla', ['token' => $annulla]);
        $this->assertStatus(200);
        self::assertCount(1, $this->auditRows('procedura.annullata_da_avviso', 'denied'));

        // Nuova richiesta, questa volta va fino in fondo.
        $this->request('POST', '/api/auth/recupero', ['email' => 'giulia@example.com']);
        $this->request('POST', '/api/auth/recupero/conferma', ['token' => $this->tokenDa($this->ultimaEmail(), 'conferma')]);
        $p2 = $this->request('GET', '/api/admin/procedure?stato=identita_da_verificare', token: $supporto)[0];
        $this->request('POST', "/api/admin/procedure/{$p2['id']}/identita", ['esito' => 'verificata', 'documento' => 'Passaporto …77', 'come' => 'di persona'], $supporto);
        $this->em()->getConnection()->executeStatement("UPDATE procedura_accesso SET eseguibile_il = NOW() - INTERVAL '1 minute' WHERE id = ?", [$p2['id']]);
        self::assertSame(1, $this->runJob()['procedure_eseguite']);

        $dopo = $this->fresh($giulia);
        self::assertFalse($dopo->isTwoFactorEnabled());
        self::assertTrue($dopo->isTwoFactorObbligatoria());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM refresh_tokens WHERE username = ?', ['giulia@example.com']));
        $login = $this->request('POST', '/api/auth/login', ['email' => 'giulia@example.com', 'password' => self::PASSWORD]);
        self::assertArrayHasKey('token', $login, 'senza 2FA si entra con la password');
        self::assertTrue($this->request('GET', '/api/me', token: $login['token'])['twoFactorObbligatoria']);
        self::assertNotEmpty($secret);
    }

    public function testOrphanFamilyGetsNewOwnerAfterIdentityCheckAnd72Hours(): void
    {
        [$famiglia, $mario] = $this->famiglia('mario@example.com');
        $giulia = $this->createUser('giulia@example.com');
        $mGiulia = $this->addMember($giulia, $famiglia, Role::MEMBER);
        $supporto = $this->login($this->supporto);

        $this->request('POST', "/api/admin/tenants/{$famiglia->getId()}/nuovo-proprietario", ['membershipId' => (string) $mGiulia->getId(), 'documento' => 'CI …1', 'come' => 'videochiamata'], $supporto);
        $this->assertStatus(409);

        // L'unico proprietario perde l'account: la famiglia resta senza proprietario.
        $m = $this->fresh($mario);
        $m->setActive(false);
        $this->em()->flush();
        self::assertSame('senza_proprietario', $this->request('GET', '/api/admin/tenants?stato=senza_proprietario', token: $supporto)[0]['stato']);
        self::assertContains('senza_proprietario', array_column($this->request('GET', '/api/admin/salute', token: $supporto)['daFare'], 'tipo'));

        $this->mailer()->reset();
        $p = $this->request('POST', "/api/admin/tenants/{$famiglia->getId()}/nuovo-proprietario", ['membershipId' => (string) $mGiulia->getId(), 'documento' => 'CI …1', 'come' => 'videochiamata'], $supporto);
        $this->assertStatus(201);
        self::assertSame('in_attesa', $p['stato']);
        self::assertGreaterThanOrEqual(1, \count($this->mailer()->getEvents()->getMessages()));

        $this->em()->getConnection()->executeStatement("UPDATE procedura_accesso SET eseguibile_il = NOW() - INTERVAL '1 minute' WHERE id = ?", [$p['id']]);
        $this->runJob();
        self::assertSame('owner', $this->em()->getConnection()->fetchOne('SELECT r.slug FROM membership m JOIN role r ON r.id = m.role_id WHERE m.id = ?', [(string) $mGiulia->getId()]));
        self::assertSame([], $this->request('GET', '/api/admin/tenants?stato=senza_proprietario', token: $supporto));
    }

    public function testSupportPreparesEnteVerificationButOnlySuperadminApproves(): void
    {
        $segretaria = $this->createUser('segreteria@scuola.example');
        $tid = $this->request('POST', '/api/enti', ['tipo' => 'scuola', 'denominazione' => 'Scuola Verdi', 'codiceFiscale' => '80012345676', 'codiceMeccanografico' => 'RMEE000001'], $this->login($segretaria))['tenantId'];
        $supporto = $this->login($this->supporto);
        self::assertCount(1, $this->request('GET', '/api/admin/enti', token: $supporto));
        $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'approva', 'controlli' => ['registro' => true]], $supporto);
        $this->assertStatus(403);
        $this->request('POST', "/api/admin/enti/$tid/decisione", ['azione' => 'integrazioni', 'nota' => 'Manca la sede legale'], $supporto);
        $this->assertStatus(200);
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array{0: Tenant, 1: User} */
    private function famiglia(string $email): array
    {
        $user = $this->createUser($email);
        $tenant = $this->createTenant('Famiglia Rossi');
        $this->addMember($user, $tenant, Role::OWNER);

        return [$tenant, $user];
    }

    private function tenantByName(string $name): Tenant
    {
        return $this->em()->getRepository(Tenant::class)->findOneBy(['name' => $name]);
    }

    private function enableTwoFactor(User $user): string
    {
        $token = $this->login($user);
        $setup = $this->request('POST', '/api/me/2fa/setup', [], $token);
        $this->request('POST', '/api/me/2fa/enable', ['code' => static::getContainer()->get(TotpService::class)->currentCode($setup['secret'], -1)], $token);
        $this->assertStatus(200);

        return $setup['secret'];
    }

    /** @return array<string, int> */
    private function runJob(): array
    {
        $result = static::getContainer()->get(PuliziaPeriodicaHandler::class)(new PuliziaPeriodicaMessage());
        $this->em()->clear();

        return $result;
    }
}
