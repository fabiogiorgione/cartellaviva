<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use Symfony\Component\Mime\Email;

/**
 * Gestione manuale dal portale: famiglie, enti e membri creati dal
 * superadmin, link di attivazione, avvisi ai proprietari, invito delle
 * famiglie a collegarsi a un ente.
 */
final class GestioneManualeTest extends ApiTestCase
{
    private const CF = '80012345676';

    private User $admin;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $this->adminToken = $this->login($this->admin);
    }

    public function testOnlySuperadminManages(): void
    {
        $supporto = $this->createUser('supporto@cartellaviva.local');
        $this->request('PUT', "/api/admin/utenti/{$supporto->getId()}/ruolo-piattaforma", ['ruolo' => 'supporto'], $this->adminToken);
        $token = $this->login($supporto);

        $this->request('POST', '/api/admin/famiglie', ['nome' => 'Famiglia Neri', 'proprietario' => ['email' => 'anna@example.com', 'nome' => 'Anna', 'cognome' => 'Neri']], $token);
        $this->assertStatus(403);
        $this->request('POST', '/api/admin/famiglie', ['nome' => 'Famiglia Neri', 'proprietario' => ['email' => 'anna@example.com', 'nome' => 'Anna', 'cognome' => 'Neri']], $this->login($this->createUser('mario@example.com')));
        $this->assertStatus(403);
    }

    public function testCreateFamilyWithNewOwnerWhoActivatesAccount(): void
    {
        $esistente = $this->createUser('luca@example.com');
        $this->mailer()->reset();

        $famiglia = $this->request('POST', '/api/admin/famiglie', [
            'nome' => 'Famiglia Neri',
            'proprietario' => ['email' => ' Anna@Example.com ', 'nome' => 'Anna', 'cognome' => 'Neri'],
            'membri' => [['email' => 'luca@example.com', 'ruolo' => Role::MEMBER]],
        ], $this->adminToken);
        $this->assertStatus(201);

        // Anna (nuova) riceve l'attivazione, Luca (esistente) un avviso.
        $attivazione = $this->emailPer('anna@example.com');
        self::assertSame('Attiva il tuo account CartellaViva', $attivazione->getSubject());
        self::assertStringContainsString('Famiglia Neri', (string) $attivazione->getHtmlBody());
        self::assertStringContainsString('/auth/attiva?token=', (string) $attivazione->getHtmlBody());
        self::assertStringContainsString('ti ha aggiunto a &quot;Famiglia Neri&quot;', (string) $this->emailPer('luca@example.com')->getHtmlBody());

        $dettaglio = $this->request('GET', "/api/admin/tenants/{$famiglia['id']}", token: $this->adminToken);
        $anna = array_values(array_filter($dettaglio['membri'], static fn ($m) => 'anna@example.com' === $m['email']))[0];
        self::assertTrue($anna['proprietario']);
        self::assertTrue($anna['inAttesaAttivazione']);

        // Senza password non si entra; con il link si sceglie e si entra.
        $this->request('POST', '/api/auth/login', ['email' => 'anna@example.com', 'password' => 'Una-Password-Nuova-2026']);
        $this->assertStatus(401);
        $token = $this->tokenDa($attivazione);
        self::assertSame('a•••@example.com', $this->request('POST', '/api/auth/attivazione/anteprima', ['token' => $token])['email']);
        $this->request('POST', '/api/auth/attivazione', ['token' => $token, 'password' => 'Una-Password-Nuova-2026']);
        $this->assertStatus(200);
        $this->request('POST', '/api/auth/attivazione', ['token' => $token, 'password' => 'Una-Password-Nuova-2026']);
        $this->assertStatus(410);
        $login = $this->request('POST', '/api/auth/login', ['email' => 'anna@example.com', 'password' => 'Una-Password-Nuova-2026']);
        $this->assertStatus(200);
        $me = $this->request('GET', '/api/me', token: $login['token']);
        self::assertTrue($me['emailVerificata']);
        self::assertSame('Famiglia Neri', $me['memberships'][0]['tenant']['name']);
        self::assertSame('owner', $me['memberships'][0]['role']['slug']);

        // Già attivo: niente nuovo link.
        $annaId = $anna['userId'];
        $this->request('POST', "/api/admin/utenti/{$annaId}/attivazione", token: $this->adminToken);
        $this->assertStatus(409);
        self::assertNotEmpty($this->auditRows('portale.famiglia_creata'));
        self::assertNotEmpty($this->auditRows('user.account_attivato'));
    }

    public function testCreationIsAllOrNothing(): void
    {
        $this->request('POST', '/api/admin/famiglie', [
            'nome' => 'Famiglia Neri',
            'proprietario' => ['email' => 'anna@example.com', 'nome' => 'Anna', 'cognome' => 'Neri'],
            'membri' => [['email' => 'senza-nome@example.com', 'ruolo' => Role::MEMBER]],
        ], $this->adminToken);
        $this->assertStatus(422);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'anna@example.com']));

        // Ruolo dell'ente in una famiglia: rifiutato.
        $this->request('POST', '/api/admin/famiglie', [
            'nome' => 'Famiglia Neri',
            'proprietario' => ['email' => 'anna@example.com', 'nome' => 'Anna', 'cognome' => 'Neri'],
            'membri' => [['email' => 'b@example.com', 'nome' => 'B', 'cognome' => 'C', 'ruolo' => Role::STAFF]],
        ], $this->adminToken);
        $this->assertStatus(422);
    }

    public function testPlatformTeamCannotBecomeMember(): void
    {
        $this->request('POST', '/api/admin/famiglie', ['nome' => 'Famiglia Admin', 'proprietario' => ['email' => 'admin@cartellaviva.local']], $this->adminToken);
        $this->assertStatus(409);

        $famiglia = $this->request('POST', '/api/admin/famiglie', ['nome' => 'Famiglia Neri', 'proprietario' => ['email' => 'anna@example.com', 'nome' => 'Anna', 'cognome' => 'Neri']], $this->adminToken);
        $this->request('POST', "/api/admin/tenants/{$famiglia['id']}/membri", ['email' => 'admin@cartellaviva.local', 'ruolo' => Role::VIEWER], $this->adminToken);
        $this->assertStatus(409);
    }

    public function testCreateVerifiedEnteAndManageMembers(): void
    {
        $dati = ['tipo' => 'scuola', 'denominazione' => 'Scuola Rossi', 'codiceFiscale' => self::CF, 'codiceMeccanografico' => 'MIEE000002'];
        $this->request('POST', '/api/admin/enti', ['ente' => $dati, 'notaVerifica' => 'ok', 'proprietario' => ['email' => 'segreteria@scuola.it', 'nome' => 'Segreteria', 'cognome' => 'Rossi']], $this->adminToken);
        $this->assertStatus(422); // la nota sulla verifica è obbligatoria

        $ente = $this->request('POST', '/api/admin/enti', [
            'ente' => $dati,
            'notaVerifica' => 'Contratto firmato il 2 ottobre, chiamata alla segreteria.',
            'proprietario' => ['email' => 'segreteria@scuola.it', 'nome' => 'Segreteria', 'cognome' => 'Rossi'],
            'staff' => [['email' => 'maestra@scuola.it', 'nome' => 'Laura', 'cognome' => 'Bianchi']],
        ], $this->adminToken);
        $this->assertStatus(201);
        $tokenSegreteria = $this->tokenDa($this->emailPer('segreteria@scuola.it'));
        $dettaglio = $this->request('GET', "/api/admin/tenants/{$ente['id']}", token: $this->adminToken);
        self::assertSame('ente_verificato', $dettaglio['stato']);
        self::assertCount(2, $dettaglio['membri']);

        // Stesso codice fiscale: già registrato.
        $this->request('POST', '/api/admin/enti', ['ente' => $dati + ['denominazione' => 'Copia'], 'notaVerifica' => 'Contratto firmato ieri.', 'proprietario' => ['email' => 'x@scuola.it', 'nome' => 'X', 'cognome' => 'Y']], $this->adminToken);
        $this->assertStatus(409);

        // La segreteria attiva l'account e accede.
        $this->request('POST', '/api/auth/attivazione', ['token' => $tokenSegreteria, 'password' => 'Segreteria-Scuola-2026']);
        $this->assertStatus(200);

        // Un membro aggiunto dopo: il proprietario riceve l'avviso.
        $ruoli = $this->request('GET', "/api/admin/tenants/{$ente['id']}/ruoli", token: $this->adminToken);
        self::assertContains('segreteria', array_column($ruoli, 'slug'));
        self::assertNotContains('emergenza', array_column($ruoli, 'slug'));
        $this->mailer()->reset();
        $nuovo = $this->request('POST', "/api/admin/tenants/{$ente['id']}/membri", ['email' => 'prof@scuola.it', 'nome' => 'Paolo', 'cognome' => 'Verdi', 'ruolo' => 'staff'], $this->adminToken);
        $this->assertStatus(201);
        self::assertStringContainsString('Paolo Verdi', (string) $this->emailPer('segreteria@scuola.it')->getHtmlBody());
        $this->request('POST', "/api/admin/tenants/{$ente['id']}/membri", ['email' => 'prof@scuola.it', 'ruolo' => 'staff'], $this->adminToken);
        $this->assertStatus(409);

        // Cambio di ruolo, sospensione, rimozione.
        $this->request('PUT', "/api/admin/tenants/{$ente['id']}/membri/{$nuovo['membershipId']}", ['ruolo' => 'segreteria'], $this->adminToken);
        $this->assertStatus(200);
        self::assertSame('suspended', $this->request('PUT', "/api/admin/tenants/{$ente['id']}/membri/{$nuovo['membershipId']}", ['attivo' => false], $this->adminToken)['stato']);
        $this->request('DELETE', "/api/admin/tenants/{$ente['id']}/membri/{$nuovo['membershipId']}", token: $this->adminToken);
        $this->assertStatus(204);

        // L'ultimo proprietario non si toglie.
        $owner = array_values(array_filter($this->request('GET', "/api/admin/tenants/{$ente['id']}", token: $this->adminToken)['membri'], static fn ($m) => $m['proprietario']))[0];
        $this->request('DELETE', "/api/admin/tenants/{$ente['id']}/membri/{$owner['membershipId']}", token: $this->adminToken);
        $this->assertStatus(409);

        // Il link si reinvia finché l'account non è attivo.
        $maestra = array_values(array_filter($this->request('GET', "/api/admin/tenants/{$ente['id']}", token: $this->adminToken)['membri'], static fn ($m) => 'maestra@scuola.it' === $m['email']))[0];
        $this->request('POST', "/api/admin/utenti/{$maestra['userId']}/attivazione", token: $this->adminToken);
        $this->assertStatus(202);
    }

    public function testInviteFamilyToEnteGoesThroughEmail(): void
    {
        $ente = $this->request('POST', '/api/admin/enti', [
            'ente' => ['tipo' => 'scuola', 'denominazione' => 'Scuola Rossi', 'codiceFiscale' => self::CF, 'codiceMeccanografico' => 'MIEE000002'],
            'notaVerifica' => 'Contratto firmato il 2 ottobre.',
            'proprietario' => ['email' => 'segreteria@scuola.it', 'nome' => 'Segreteria', 'cognome' => 'Rossi'],
        ], $this->adminToken);
        self::assertSame([], $this->request('GET', "/api/admin/enti/{$ente['id']}/gruppi", token: $this->adminToken));

        $this->mailer()->reset();
        $this->request('POST', "/api/admin/enti/{$ente['id']}/inviti-famiglia", [
            'iscritto' => ['nome' => 'Leo', 'cognome' => 'Neri'],
            'referente' => ['nome' => 'Anna', 'cognome' => 'Neri', 'email' => 'anna@example.com', 'relazione' => 'madre'],
        ], $this->adminToken);
        $this->assertStatus(201);
        $invito = $this->emailPer('anna@example.com');
        self::assertStringContainsString('/collegamenti/invito?token=', (string) $invito->getHtmlBody());

        // Nessun collegamento finché la famiglia non accetta.
        $anteprima = $this->request('GET', '/api/collegamenti/anteprima?token='.$this->tokenDa($invito));
        $this->assertStatus(200);
        self::assertSame('Scuola Rossi', $anteprima['ente']['nome']);
        self::assertSame(0, (int) $this->em()->createQuery('SELECT COUNT(c.id) FROM App\Ente\Entity\Collegamento c')->getSingleScalarResult());

        // Le famiglie si invitano solo negli enti.
        $famiglia = $this->request('POST', '/api/admin/famiglie', ['nome' => 'Famiglia Neri', 'proprietario' => ['email' => 'anna@example.com']], $this->adminToken);
        $this->request('POST', "/api/admin/enti/{$famiglia['id']}/inviti-famiglia", ['iscritto' => ['nome' => 'A', 'cognome' => 'B'], 'referente' => ['nome' => 'C', 'cognome' => 'D', 'email' => 'c@example.com']], $this->adminToken);
        $this->assertStatus(404);
    }

    public function testAccountBrandIsCartellaViva(): void
    {
        $this->createUser('mario@example.com');
        $this->mailer()->reset();
        $this->request('POST', '/api/auth/password/dimenticata', ['email' => 'mario@example.com']);
        $email = $this->emailPer('mario@example.com');
        self::assertSame('CartellaViva', $email->getFrom()[0]->getName());
        self::assertStringNotContainsString('FamilyVault', (string) $email->getSubject().$email->getHtmlBody());
    }

    private function emailPer(string $indirizzo): Email
    {
        $trovate = array_values(array_filter($this->mailer()->getEvents()->getMessages(), static fn ($m) => $m instanceof Email && $m->getTo()[0]->getAddress() === $indirizzo));
        self::assertNotEmpty($trovate, 'nessuna email per '.$indirizzo);

        return end($trovate);
    }
}
