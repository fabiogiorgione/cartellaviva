<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use Symfony\Component\Mime\Email;

/**
 * Liste del portale (famiglie ed enti, utenti): filtri e paginazione,
 * modifica, eliminazione (utente anonimizzato, tenant programmato a 30
 * giorni), operazioni di gruppo ed esportazione CSV.
 */
final class PortaleElenchiTest extends ApiTestCase
{
    private User $admin;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $this->adminToken = $this->login($this->admin);
    }

    public function testListsPaginateAndFilter(): void
    {
        foreach (['Neri', 'Bianchi', 'Verdi'] as $cognome) {
            $this->famiglia($cognome);
        }
        $pagina = $this->request('GET', '/api/admin/tenants/elenco?tipo=privato&perPagina=10&pagina=1&ordina=nome', token: $this->adminToken);
        $this->assertStatus(200);
        self::assertSame(3, $pagina['totale']);
        self::assertSame(['Famiglia Bianchi', 'Famiglia Neri', 'Famiglia Verdi'], array_column($pagina['righe'], 'nome'));
        self::assertSame(['Famiglia Neri'], array_column($this->request('GET', '/api/admin/tenants/elenco?nome=ner', token: $this->adminToken)['righe'], 'nome'));
        self::assertSame(['Famiglia Verdi'], array_column($this->request('GET', '/api/admin/tenants/elenco?membro=verdi@example.com', token: $this->adminToken)['righe'], 'nome'));
        $seconda = $this->request('GET', '/api/admin/tenants/elenco?tipo=privato&perPagina=10&pagina=2', token: $this->adminToken);
        self::assertSame([], $seconda['righe']);

        $utenti = $this->request('GET', '/api/admin/utenti/elenco?stato=in_attesa&ordina=email', token: $this->adminToken);
        self::assertSame(['bianchi@example.com', 'neri@example.com', 'verdi@example.com'], array_column($utenti['righe'], 'email'));
        self::assertSame('in_attesa', $utenti['righe'][0]['stato']);
        self::assertSame('Famiglia Bianchi', $utenti['righe'][0]['appartenenze'][0]['tenant']);
        self::assertSame(1, $this->request('GET', '/api/admin/utenti/elenco?team=si', token: $this->adminToken)['totale']);
        self::assertSame(['neri@example.com'], array_column($this->request('GET', '/api/admin/utenti/elenco?tenant=neri', token: $this->adminToken)['righe'], 'email'));

        // Il supporto legge, la sola lettura no; l'export è del superadmin.
        $supporto = $this->createUser('supporto@cartellaviva.local');
        $this->request('PUT', "/api/admin/utenti/{$supporto->getId()}/ruolo-piattaforma", ['ruolo' => 'supporto'], $this->adminToken);
        $token = $this->login($supporto);
        $this->request('GET', '/api/admin/utenti/elenco', token: $token);
        $this->assertStatus(200);
        $this->request('GET', '/api/admin/utenti/export', token: $token);
        $this->assertStatus(403);
        $this->request('PUT', "/api/admin/utenti/{$supporto->getId()}", ['nome' => 'X'], $token);
        $this->assertStatus(403);
    }

    public function testEditUserEmailClosesSessionsAndNeedsNewConfirmation(): void
    {
        $mario = $this->createUser('mario@example.com');
        $sessione = $this->login($mario);
        $this->mailer()->reset();

        $this->request('PUT', "/api/admin/utenti/{$mario->getId()}", ['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@example.com'], $this->adminToken);
        $this->assertStatus(200);
        self::assertStringContainsString('m•••@example.com', (string) $this->emailPer('mario@example.com')->getHtmlBody());
        self::assertStringContainsString('/auth/verifica-email?token=', (string) $this->emailPer('mario.rossi@example.com')->getHtmlBody());

        sleep(1);
        $this->request('GET', '/api/me', token: $sessione);
        $this->assertStatus(401);
        $login = $this->request('POST', '/api/auth/login', ['email' => 'mario.rossi@example.com', 'password' => self::PASSWORD]);
        $me = $this->request('GET', '/api/me', token: $login['token']);
        self::assertSame('Mario', $me['firstName']);
        self::assertFalse($me['emailVerificata']);

        // Indirizzo già usato, e il superadmin non si modifica da qui.
        $this->createUser('anna@example.com');
        $this->request('PUT', "/api/admin/utenti/{$mario->getId()}", ['email' => 'anna@example.com'], $this->adminToken);
        $this->assertStatus(409);
        $this->request('PUT', "/api/admin/utenti/{$this->admin->getId()}", ['nome' => 'X'], $this->adminToken);
        $this->assertStatus(409);
    }

    public function testDeleteUserAnonymizesAndFreesTheAddress(): void
    {
        $anna = $this->createUser('anna@example.com');
        $luca = $this->createUser('luca@example.com');
        $famiglia = $this->createTenant('Famiglia Neri');
        $this->addMember($anna, $famiglia, Role::OWNER);
        $this->addMember($luca, $famiglia, Role::MEMBER);

        // L'unica proprietaria non si elimina.
        $risposta = $this->request('DELETE', "/api/admin/utenti/{$anna->getId()}", token: $this->adminToken);
        $this->assertStatus(409);
        self::assertStringContainsString('Famiglia Neri', $risposta['detail']);

        $this->mailer()->reset();
        $this->request('DELETE', "/api/admin/utenti/{$luca->getId()}", token: $this->adminToken);
        $this->assertStatus(204);
        // Avvisati Luca (al vecchio indirizzo) e la proprietaria.
        self::assertStringContainsString('eliminato', (string) $this->emailPer('luca@example.com')->getHtmlBody());
        self::assertStringContainsString('non fa più parte', (string) $this->emailPer('anna@example.com')->getHtmlBody());
        $this->em()->clear();
        $eliminato = $this->em()->find(User::class, $luca->getId());
        self::assertTrue($eliminato->isEliminato());
        self::assertFalse($eliminato->isActive());
        self::assertSame('Utente eliminato', $eliminato->getFullName());
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'luca@example.com']));
        self::assertCount(1, $this->request('GET', "/api/admin/tenants/{$famiglia->getId()}", token: $this->adminToken)['membri']);

        $this->request('POST', '/api/auth/login', ['email' => 'luca@example.com', 'password' => self::PASSWORD]);
        $this->assertStatus(401);

        // Non compare più tra gli utenti, solo filtrando gli eliminati; l'indirizzo è di nuovo libero.
        self::assertNotContains((string) $luca->getId(), array_column($this->request('GET', '/api/admin/utenti/elenco', token: $this->adminToken)['righe'], 'id'));
        self::assertSame('eliminato', $this->request('GET', '/api/admin/utenti/elenco?stato=eliminati', token: $this->adminToken)['righe'][0]['stato']);
        $this->request('POST', '/api/admin/famiglie', ['nome' => 'Famiglia Luca', 'proprietario' => ['email' => 'luca@example.com', 'nome' => 'Luca', 'cognome' => 'Neri']], $this->adminToken);
        $this->assertStatus(201);
        self::assertNotEmpty($this->auditRows('portale.utente_eliminato'));
    }

    public function testDeleteTenantIsScheduledAndCancellable(): void
    {
        $id = $this->famiglia('Neri');
        $this->request('DELETE', "/api/admin/tenants/{$id}", ['motivo' => ''], $this->adminToken);
        $this->assertStatus(422);
        $this->mailer()->reset();
        $esito = $this->request('DELETE', "/api/admin/tenants/{$id}", ['motivo' => 'Richiesta della famiglia per telefono.'], $this->adminToken);
        $this->assertStatus(202);
        self::assertNotNull($esito['eliminazioneProgrammataIl']);
        self::assertSame([$id], array_column($this->request('GET', '/api/admin/tenants/elenco?stato=in_eliminazione', token: $this->adminToken)['righe'], 'id'));
        $this->request('DELETE', "/api/admin/tenants/{$id}", ['motivo' => 'Di nuovo, per sbaglio.'], $this->adminToken);
        $this->assertStatus(409);

        $this->request('POST', "/api/admin/tenants/{$id}/annulla-eliminazione", token: $this->adminToken);
        $this->assertStatus(200);
        self::assertSame([], $this->request('GET', '/api/admin/tenants/elenco?stato=in_eliminazione', token: $this->adminToken)['righe']);
        self::assertNotEmpty($this->auditRows('portale.tenant_eliminazione_programmata'));
    }

    public function testEditEnteStaysVerified(): void
    {
        $ente = $this->request('POST', '/api/admin/enti', [
            'ente' => ['tipo' => 'scuola', 'denominazione' => 'Scuola Rossi', 'codiceFiscale' => '80012345676', 'codiceMeccanografico' => 'MIEE000002'],
            'notaVerifica' => 'Contratto firmato il 2 ottobre.',
            'proprietario' => ['email' => 'segreteria@scuola.it', 'nome' => 'Segreteria', 'cognome' => 'Rossi'],
        ], $this->adminToken);
        $this->request('PUT', "/api/admin/tenants/{$ente['id']}", ['denominazione' => 'Scuola G. Rossi', 'codiceFiscale' => '80012345676', 'codiceMeccanografico' => 'MIEE000003'], $this->adminToken);
        $this->assertStatus(200);
        $dettaglio = $this->request('GET', "/api/admin/tenants/{$ente['id']}", token: $this->adminToken);
        self::assertSame('Scuola G. Rossi', $dettaglio['nome']);
        self::assertSame('ente_verificato', $dettaglio['stato']);
        self::assertSame('miee000003@istruzione.it', $dettaglio['ente']['emailIstituzionale']);

        $famiglia = $this->famiglia('Neri');
        $this->request('PUT', "/api/admin/tenants/{$famiglia}", ['nome' => ''], $this->adminToken);
        $this->assertStatus(422);
        $this->request('PUT', "/api/admin/tenants/{$famiglia}", ['nome' => 'Famiglia Neri-Bianchi'], $this->adminToken);
        $this->assertStatus(200);
    }

    public function testBulkAndCsvExport(): void
    {
        $this->request('POST', '/api/admin/famiglie', ['nome' => 'Famiglia Neri', 'proprietario' => ['email' => 'anna@example.com', 'nome' => '=SOMMA(1;2)', 'cognome' => 'Neri']], $this->adminToken);
        $luca = $this->createUser('luca@example.com');

        $esito = $this->request('POST', '/api/admin/utenti/bulk', ['azione' => 'blocca', 'ids' => [(string) $luca->getId(), (string) $this->admin->getId(), 'non-un-id']], $this->adminToken);
        $this->assertStatus(200);
        self::assertSame(1, $esito['fatti']);
        self::assertCount(2, $esito['saltati']);
        self::assertSame(['luca@example.com'], array_column($this->request('GET', '/api/admin/utenti/elenco?stato=bloccati', token: $this->adminToken)['righe'], 'email'));

        $anna = $this->request('GET', '/api/admin/utenti/elenco?email=anna', token: $this->adminToken)['righe'][0];
        $this->mailer()->reset();
        $esito = $this->request('POST', '/api/admin/utenti/bulk', ['azione' => 'attivazione', 'ids' => [$anna['id'], (string) $luca->getId()]], $this->adminToken);
        self::assertSame(1, $esito['fatti'], 'Luca ha già la password: saltato');
        $this->emailPer('anna@example.com');

        $this->client->request('GET', '/api/admin/utenti/export?email=anna', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken]);
        $this->assertStatus(200);
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF".'email;nome;stato', $csv);
        self::assertStringContainsString("'=SOMMA(1;2) Neri", $csv, 'formula neutralizzata');
        $this->client->request('GET', '/api/admin/tenants/export?tipo=privato', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken]);
        self::assertStringContainsString('"Famiglia Neri";famiglia;attivo', (string) $this->client->getResponse()->getContent());
        self::assertCount(2, $this->auditRows('portale.export'));
    }

    /** Famiglia creata dal portale con un proprietario nuovo; restituisce l'id. */
    private function famiglia(string $cognome): string
    {
        $r = $this->request('POST', '/api/admin/famiglie', ['nome' => 'Famiglia '.$cognome, 'proprietario' => ['email' => mb_strtolower($cognome).'@example.com', 'nome' => 'Anna', 'cognome' => $cognome]], $this->adminToken);
        $this->assertStatus(201);

        return $r['id'];
    }

    private function emailPer(string $indirizzo): Email
    {
        $trovate = array_values(array_filter($this->mailer()->getEvents()->getMessages(), static fn ($m) => $m instanceof Email && $m->getTo()[0]->getAddress() === $indirizzo));
        self::assertNotEmpty($trovate, 'nessuna email per '.$indirizzo);

        return end($trovate);
    }
}
