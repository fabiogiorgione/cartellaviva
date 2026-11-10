<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Account\Registrazione;
use App\Account\Repository\UserRepository;
use App\Tenancy\Entity\Role;
use App\Tests\Support\TestGoogleJwksProvider;
use Symfony\Component\Mime\Email;

/**
 * REGISTRAZIONE_APERTA=0: nessuno si crea un account da solo; inviti e portale funzionano.
 */
final class RegistrazioneChiusaTest extends ApiTestCase
{
    private const NUOVA = ['email' => 'nuova@example.com', 'password' => 'una-password-lunga', 'firstName' => 'Anna', 'lastName' => 'Verdi'];

    public function testLoStatoEPubblico(): void
    {
        self::assertSame(['aperta' => true], $this->request('GET', '/api/auth/registrazione'));
        $this->assertStatus(200);
    }

    public function testLoStatoDiceChiusa(): void
    {
        $this->chiudi();

        self::assertSame(['aperta' => false], $this->request('GET', '/api/auth/registrazione'));
    }

    public function testRegistrazioneRifiutataSenzaCreareNulla(): void
    {
        $this->chiudi();

        $risposta = $this->request('POST', '/api/auth/register', self::NUOVA);

        $this->assertStatus(403);
        self::assertSame(Registrazione::MESSAGGIO_CHIUSA, $risposta['detail']);
        self::assertNull($this->utente('nuova@example.com'));
        self::assertCount(1, $this->auditRows('user.registered', 'failure'));
        self::assertEmailCount(0);
    }

    public function testNonSiScopreSeUnEmailEsiste(): void
    {
        $this->createUser('esistente@example.com');
        $this->chiudi();

        // Aperta risponderebbe 409 "esiste già": chiusa, la stessa risposta per tutti.
        $this->request('POST', '/api/auth/register', ['email' => 'esistente@example.com'] + self::NUOVA);
        $this->assertStatus(403);
    }

    public function testGoogleNonCreaAccountNuovi(): void
    {
        $this->chiudi();

        $risposta = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-nuovo', 'sconosciuto@example.com')]);

        $this->assertStatus(403);
        self::assertSame(Registrazione::MESSAGGIO_CHIUSA, $risposta['detail']);
        self::assertNull($this->utente('sconosciuto@example.com'));
        self::assertCount(1, $this->auditRows('auth.login', 'failure'));
    }

    public function testGoogleFaEntrareChiHaGiaUnAccount(): void
    {
        $this->createUser('esistente@example.com');
        $this->chiudi();

        $risposta = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-esistente', 'esistente@example.com')]);

        $this->assertStatus(200);
        self::assertFalse($risposta['isNewUser']);
        self::assertNotEmpty($risposta['token']);
    }

    public function testChiInvitatoInFamigliaCreaLAccount(): void
    {
        $tenant = $this->createTenant('Famiglia Rossi');
        $owner = $this->createUser('owner@example.com');
        $this->addMember($owner, $tenant, Role::OWNER);
        $this->chiudi();

        $this->request('POST', "/api/tenants/{$tenant->getId()}/invitations", [
            'email' => 'nonno@example.com',
            'roleId' => (string) $this->systemRole(Role::VIEWER)->getId(),
        ], $this->login($owner));
        $this->assertStatus(201);
        $token = $this->token(self::getMailerMessage(), '/auth/invite');

        $this->request('POST', '/api/invitations/accept', ['token' => $token, 'firstName' => 'Giuseppe', 'lastName' => 'Rossi', 'password' => 'password-del-nonno']);

        $this->assertStatus(201);
        $this->request('POST', '/api/auth/login', ['email' => 'nonno@example.com', 'password' => 'password-del-nonno']);
        $this->assertStatus(200);
    }

    public function testIlPortaleCreaUnaFamigliaConIlSuoProprietario(): void
    {
        $adminToken = $this->login($this->createUser('admin@cartellaviva.local', superAdmin: true));
        $this->chiudi();

        $this->request('POST', '/api/admin/famiglie', [
            'nome' => 'Famiglia Neri',
            'proprietario' => ['email' => 'anna@example.com', 'nome' => 'Anna', 'cognome' => 'Neri'],
        ], $adminToken);
        $this->assertStatus(201);
        $token = $this->token(self::getMailerMessage(), '/auth/attiva');

        $this->request('POST', '/api/auth/attivazione', ['token' => $token, 'password' => 'Una-Password-Nuova-2026']);

        $this->assertStatus(200);
        $this->request('POST', '/api/auth/login', ['email' => 'anna@example.com', 'password' => 'Una-Password-Nuova-2026']);
        $this->assertStatus(200);
    }

    /** Da chiamare prima della prima richiesta del test: il kernel non si riavvia (disableReboot). */
    private function chiudi(): void
    {
        static::getContainer()->set(Registrazione::class, new Registrazione(false));
    }

    private function utente(string $email): ?User
    {
        return static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
    }

    private function token(?\Symfony\Component\Mime\RawMessage $email, string $percorso): string
    {
        self::assertInstanceOf(Email::class, $email);
        $schema = '#'.preg_quote($percorso, '#').'\?token=([A-Za-z0-9_-]+)#';
        self::assertMatchesRegularExpression($schema, (string) $email->getHtmlBody());
        preg_match($schema, (string) $email->getHtmlBody(), $m);

        return $m[1];
    }
}
