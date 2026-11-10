<?php

namespace App\Tests\Functional;

use App\Account\TwoFactor\TotpService;
use App\Tenancy\Entity\Role;
use App\Tests\Support\TestGoogleJwksProvider;

/**
 * Fase 6: password dimenticata, verifica dell'email, cambio di password ed
 * email, chiusura delle sessioni, protezione dagli account "preoccupati".
 */
final class AccountTest extends ApiTestCase
{
    private const NUOVA = 'una-password-nuova-e-lunga';

    public function testForgotPasswordFlowClosesSessionsAndKeepsTwoFactor(): void
    {
        $this->createUser('mario@example.com');
        $login = $this->request('POST', '/api/auth/login', ['email' => 'mario@example.com', 'password' => self::PASSWORD]);
        $this->mailer()->reset();

        // Stessa risposta per indirizzi sconosciuti, e nessuna email.
        $this->request('POST', '/api/auth/password/dimenticata', ['email' => 'nessuno@example.com']);
        $this->assertStatus(202);
        self::assertCount(0, $this->mailer()->getEvents()->getMessages());

        $this->request('POST', '/api/auth/password/dimenticata', ['email' => ' Mario@Example.com ']);
        $this->assertStatus(202);
        $primo = $this->tokenDa($this->ultimaEmail());
        $this->request('POST', '/api/auth/password/dimenticata', ['email' => 'mario@example.com']);
        $token = $this->tokenDa($this->ultimaEmail());
        self::assertStringNotContainsString(self::PASSWORD, (string) $this->ultimaEmail()->getHtmlBody());

        // Il nuovo link invalida il precedente.
        $this->request('POST', '/api/auth/password/anteprima', ['token' => $primo]);
        $this->assertStatus(410);
        self::assertSame('m•••@example.com', $this->request('POST', '/api/auth/password/anteprima', ['token' => $token])['email']);

        $this->request('POST', '/api/auth/password/reimposta', ['token' => $token, 'password' => 'corta']);
        $this->assertStatus(422);

        sleep(1); // i JWT emessi prima del cambio (iat al secondo) devono risultare vecchi
        $this->mailer()->reset();
        $this->request('POST', '/api/auth/password/reimposta', ['token' => $token, 'password' => self::NUOVA]);
        $this->assertStatus(200);
        self::assertStringContainsString('password', (string) $this->ultimaEmail()->getSubject());

        // Sessioni chiuse: access token e refresh token precedenti non valgono più.
        $this->request('GET', '/api/me', token: $login['token']);
        $this->assertStatus(401);
        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertStatus(401);
        $this->request('POST', '/api/auth/password/reimposta', ['token' => $token, 'password' => self::NUOVA]);
        $this->assertStatus(410);

        $this->request('POST', '/api/auth/login', ['email' => 'mario@example.com', 'password' => self::PASSWORD]);
        $this->assertStatus(401);
        $nuovo = $this->request('POST', '/api/auth/login', ['email' => 'mario@example.com', 'password' => self::NUOVA]);
        $this->assertStatus(200);
        self::assertArrayHasKey('token', $nuovo);
        self::assertNotEmpty($this->auditRows('user.password_reset', 'success'));

    }

    public function testResetKeepsTwoFactor(): void
    {
        $luigi = $this->createUser('luigi@example.com');
        $token = $this->login($luigi);
        $setup = $this->request('POST', '/api/me/2fa/setup', [], $token);
        $this->request('POST', '/api/me/2fa/enable', ['code' => static::getContainer()->get(TotpService::class)->currentCode($setup['secret'], -1)], $token);
        $this->assertStatus(200);

        $this->request('POST', '/api/auth/password/dimenticata', ['email' => 'luigi@example.com']);
        $esito = $this->request('POST', '/api/auth/password/reimposta', ['token' => $this->tokenDa($this->ultimaEmail()), 'password' => self::NUOVA]);
        self::assertStringContainsString('due passaggi', $esito['messaggio']);
        self::assertTrue($this->request('POST', '/api/auth/login', ['email' => 'luigi@example.com', 'password' => self::NUOVA])['twoFactorRequired']);
    }

    public function testResetRequestsAreThrottledPerUser(): void
    {
        $this->createUser('mario@example.com');
        $inviate = 0;
        for ($i = 0; $i < 5; ++$i) {
            $this->request('POST', '/api/auth/password/dimenticata', ['email' => 'mario@example.com']);
            $this->assertStatus(202);
            $inviate += \count($this->mailer()->getEvents()->getMessages());
        }
        self::assertSame(3, $inviate);
    }

    public function testRegistrationRequiresEmailVerificationToInvite(): void
    {
        $this->mailer()->reset();
        $reg = $this->request('POST', '/api/auth/register', ['email' => 'anna@example.com', 'password' => self::NUOVA, 'firstName' => 'Anna', 'lastName' => 'Verdi']);
        $this->assertStatus(201);
        $link = $this->tokenDa($this->ultimaEmail());
        self::assertStringContainsString('/auth/verifica-email?token=', (string) $this->ultimaEmail()->getHtmlBody());

        $token = $this->request('POST', '/api/auth/login', ['email' => 'anna@example.com', 'password' => self::NUOVA])['token'];
        self::assertFalse($this->request('GET', '/api/me', token: $token)['emailVerificata']);
        $invito = ['email' => 'nonno@example.com', 'roleId' => (string) $this->systemRole(Role::VIEWER)->getId()];
        $this->request('POST', "/api/tenants/{$reg['tenantId']}/invitations", $invito, $token);
        $this->assertStatus(403);
        $this->request('POST', '/api/enti', ['nome' => 'Scuola'], $token);
        $this->assertStatus(403);

        // "Invia di nuovo": il link precedente non vale più.
        $this->request('POST', '/api/me/email/verifica', token: $token);
        $this->assertStatus(202);
        $nuovoLink = $this->tokenDa($this->ultimaEmail());
        $this->request('POST', '/api/auth/verifica-email', ['token' => $link]);
        $this->assertStatus(410);
        $this->request('POST', '/api/auth/verifica-email', ['token' => $nuovoLink]);
        $this->assertStatus(200);

        self::assertTrue($this->request('GET', '/api/me', token: $token)['emailVerificata']);
        $this->request('POST', "/api/tenants/{$reg['tenantId']}/invitations", $invito, $token);
        $this->assertStatus(201);
    }

    public function testChangePasswordKeepsThisSessionOnly(): void
    {
        $this->createUser('mario@example.com');
        $altro = $this->request('POST', '/api/auth/login', ['email' => 'mario@example.com', 'password' => self::PASSWORD]);
        $token = $this->login($this->createUser('x@example.com')); // un altro utente non è toccato
        $questa = $this->request('POST', '/api/auth/login', ['email' => 'mario@example.com', 'password' => self::PASSWORD]);

        $this->request('POST', '/api/me/password', ['attuale' => 'sbagliata', 'nuova' => self::NUOVA], $questa['token']);
        $this->assertStatus(422);
        sleep(1);
        $nuovi = $this->request('POST', '/api/me/password', ['attuale' => self::PASSWORD, 'nuova' => self::NUOVA], $questa['token']);
        $this->assertStatus(200);
        self::assertArrayHasKey('refresh_token', $nuovi);

        $this->request('GET', '/api/me', token: $nuovi['token']);
        $this->assertStatus(200);
        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $altro['refresh_token']]);
        $this->assertStatus(401);
        $this->request('GET', '/api/me', token: $token);
        $this->assertStatus(200);
    }

    public function testGoogleAccountSetsFirstPasswordWithoutCurrentOne(): void
    {
        $login = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-pw', 'gina@example.com')]);
        self::assertTrue($this->request('GET', '/api/me', token: $login['token'])['emailVerificata']);
        $this->request('POST', '/api/me/password', ['nuova' => self::NUOVA], $login['token']);
        $this->assertStatus(200);
        $this->request('POST', '/api/auth/login', ['email' => 'gina@example.com', 'password' => self::NUOVA]);
        $this->assertStatus(200);
    }

    public function testChangeEmailNeedsConfirmationFromNewAddress(): void
    {
        $mario = $this->createUser('mario@example.com');
        $this->createUser('preso@example.com');
        $token = $this->login($mario);

        $this->request('POST', '/api/me/email', ['email' => 'nuovo@example.com', 'password' => 'sbagliata'], $token);
        $this->assertStatus(422);
        $errore = $this->request('POST', '/api/me/email', ['email' => 'Preso@example.com', 'password' => self::PASSWORD], $token);
        $this->assertStatus(409);
        // Errore di dominio da un controller: arriva con il suo messaggio.
        self::assertSame('Questo indirizzo è già usato da un altro account.', $errore['detail']);

        $this->mailer()->reset();
        $this->request('POST', '/api/me/email', ['email' => 'Nuovo@Example.com', 'password' => self::PASSWORD], $token);
        $this->assertStatus(202);
        [$conferma, $avviso] = $this->mailer()->getEvents()->getMessages();
        self::assertSame('nuovo@example.com', $conferma->getTo()[0]->getAddress());
        self::assertSame('mario@example.com', $avviso->getTo()[0]->getAddress());
        self::assertStringContainsString('n•••@example.com', (string) $avviso->getHtmlBody());
        // Finché non si conferma non cambia nulla.
        self::assertSame('mario@example.com', $this->request('GET', '/api/me', token: $token)['email']);

        $this->mailer()->reset();
        $this->request('POST', '/api/auth/cambio-email/conferma', ['token' => $this->tokenDa($conferma)]);
        $this->assertStatus(200);
        self::assertSame('mario@example.com', $this->ultimaEmail()->getTo()[0]->getAddress(), 'avviso al vecchio indirizzo');

        // Il vecchio token portava la vecchia email: non vale più.
        $this->request('GET', '/api/me', token: $token);
        $this->assertStatus(401);
        $this->request('POST', '/api/auth/login', ['email' => 'mario@example.com', 'password' => self::PASSWORD]);
        $this->assertStatus(401);
        $nuovo = $this->request('POST', '/api/auth/login', ['email' => 'nuovo@example.com', 'password' => self::PASSWORD]);
        self::assertTrue($this->request('GET', '/api/me', token: $nuovo['token'])['emailVerificata']);
        $this->request('POST', '/api/auth/cambio-email/conferma', ['token' => $this->tokenDa($conferma)]);
        $this->assertStatus(410);
    }

    /**
     * Chi conosce la password chiede il cambio email verso un suo
     * indirizzo; la vittima, avvisata, cambia o reimposta la password. Il link
     * di cambio in sospeso non deve più valere.
     */
    public function testPasswordChangeOrResetCancelsPendingEmailChange(): void
    {
        $mario = $this->createUser('mario@example.com');

        // La vittima usa "password dimenticata".
        $this->mailer()->reset();
        $this->request('POST', '/api/me/email', ['email' => 'intruso@example.com', 'password' => self::PASSWORD], $this->login($mario));
        $this->assertStatus(202);
        $linkIntruso = $this->tokenDa($this->mailer()->getEvents()->getMessages()[0]);
        $this->request('POST', '/api/auth/password/dimenticata', ['email' => 'mario@example.com']);
        $this->request('POST', '/api/auth/password/reimposta', ['token' => $this->tokenDa($this->ultimaEmail()), 'password' => self::NUOVA]);
        $this->assertStatus(200);
        $this->request('POST', '/api/auth/cambio-email/conferma', ['token' => $linkIntruso]);
        $this->assertStatus(410);

        // La vittima cambia la password da dentro l'app.
        sleep(1);
        $sessione = $this->request('POST', '/api/auth/login', ['email' => 'mario@example.com', 'password' => self::NUOVA])['token'];
        $this->mailer()->reset();
        $this->request('POST', '/api/me/email', ['email' => 'intruso@example.com', 'password' => self::NUOVA], $sessione);
        $this->assertStatus(202);
        $linkIntruso = $this->tokenDa($this->mailer()->getEvents()->getMessages()[0]);
        $this->request('POST', '/api/me/password', ['attuale' => self::NUOVA, 'nuova' => self::NUOVA.'-2'], $sessione);
        $this->assertStatus(200);
        $this->request('POST', '/api/auth/cambio-email/conferma', ['token' => $linkIntruso]);
        $this->assertStatus(410);

        self::assertSame('mario@example.com', $this->fresh($mario)->getEmail());
    }

    public function testGoogleReclaimsUnverifiedAccountRegisteredBySomeoneElse(): void
    {
        // Qualcuno registra per primo l'indirizzo di Gina (senza poterlo verificare).
        $this->request('POST', '/api/auth/register', ['email' => 'gina@example.com', 'password' => 'password-dell-intruso', 'firstName' => 'X', 'lastName' => 'Y']);
        $intruso = $this->request('POST', '/api/auth/login', ['email' => 'gina@example.com', 'password' => 'password-dell-intruso']);
        sleep(1);

        $gina = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-gina', 'gina@example.com')]);
        $this->assertStatus(200);
        self::assertTrue($this->request('GET', '/api/me', token: $gina['token'])['emailVerificata']);
        $this->request('POST', '/api/auth/login', ['email' => 'gina@example.com', 'password' => 'password-dell-intruso']);
        $this->assertStatus(401);
        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $intruso['refresh_token']]);
        $this->assertStatus(401);
        self::assertNotEmpty($this->auditRows('user.account_reclaimed'));
    }
}
