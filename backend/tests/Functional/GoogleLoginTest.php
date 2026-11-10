<?php

namespace App\Tests\Functional;

use App\Tests\Support\TestGoogleJwksProvider;

final class GoogleLoginTest extends ApiTestCase
{
    public function testNewGoogleUserGetsTokensAndMustCompleteProfile(): void
    {
        $response = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-123', 'Nuovo.Google@Example.com')]);

        $this->assertStatus(200);
        self::assertTrue($response['isNewUser']);
        self::assertNotEmpty($response['token']);

        $me = $this->request('GET', '/api/me', token: $response['token']);
        self::assertSame('nuovo.google@example.com', $me['email']);
        self::assertFalse($me['profileCompleted'], 'L\'anagrafica va compilata dopo il primo accesso con Google');
        self::assertFalse($me['hasPassword']);
        self::assertTrue($me['googleLinked']);
        self::assertSame([], $me['memberships'], 'Il tenant si crea al completamento del profilo');

        // Secondo accesso: stesso utente, non più "nuovo".
        $again = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-123', 'nuovo.google@example.com')]);
        self::assertFalse($again['isNewUser']);
    }

    public function testExistingAccountIsLinkedByVerifiedEmail(): void
    {
        $this->createUser('esistente@example.com');

        $response = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-456', 'esistente@example.com')]);

        $this->assertStatus(200);
        self::assertFalse($response['isNewUser']);
        self::assertCount(1, $this->auditRows('user.google_linked', 'success'));
    }

    public function testInvalidTokensAreRejected(): void
    {
        $cases = [
            'audience di un\'altra app' => TestGoogleJwksProvider::sign('g-1', 'a@example.com', ['aud' => 'other-app.apps.googleusercontent.com']),
            'email non verificata' => TestGoogleJwksProvider::sign('g-2', 'b@example.com', ['email_verified' => false]),
            'scaduto' => TestGoogleJwksProvider::sign('g-3', 'c@example.com', ['exp' => time() - 3600, 'iat' => time() - 7200]),
            'emittente errato' => TestGoogleJwksProvider::sign('g-4', 'd@example.com', ['iss' => 'https://evil.example']),
            'firma manomessa' => TestGoogleJwksProvider::sign('g-5', 'e@example.com').'x',
        ];

        foreach ($cases as $label => $idToken) {
            $this->request('POST', '/api/auth/google', ['idToken' => $idToken]);
            self::assertSame(401, $this->client->getResponse()->getStatusCode(), $label);
        }
        self::assertCount(\count($cases), $this->auditRows('auth.login', 'failure'));
    }

    public function testGoogleLoginStillRequiresSecondFactorWhenEnabled(): void
    {
        $user = $this->createUser('g2fa@example.com');
        $token = $this->login($user);
        $setup = $this->request('POST', '/api/me/2fa/setup', [], $token);
        $totp = static::getContainer()->get(\App\Account\TwoFactor\TotpService::class);
        $this->request('POST', '/api/me/2fa/enable', ['code' => $totp->currentCode($setup['secret'], -1)], $token);

        $response = $this->request('POST', '/api/auth/google', ['idToken' => TestGoogleJwksProvider::sign('g-789', 'g2fa@example.com')]);

        $this->assertStatus(200);
        self::assertTrue($response['twoFactorRequired']);
        self::assertArrayNotHasKey('token', $response);
    }
}
