<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;

final class TwoFactorTest extends ApiTestCase
{
    public function testEnrollmentLoginWithTotpAndBackupCode(): void
    {
        $user = $this->createUser('tfa@example.com');
        $token = $this->login($user);

        // Attivazione: segreto + URI otpauth per il QR.
        $setup = $this->request('POST', '/api/me/2fa/setup', [], $token);
        $this->assertStatus(200);
        self::assertStringStartsWith('otpauth://totp/CartellaViva', $setup['otpauthUri']);

        // Codice sbagliato: rifiutato.
        $this->request('POST', '/api/me/2fa/enable', ['code' => '000000'], $token);
        $this->assertStatus(422);

        $codiceAttivazione = $this->totp()->currentCode($setup['secret'], -1);
        $enabled = $this->request('POST', '/api/me/2fa/enable', ['code' => $codiceAttivazione], $token);
        $this->assertStatus(200);
        self::assertCount(10, $enabled['backupCodes']);

        // Da ora la password da sola non basta: si riceve una challenge, non un token.
        $step1 = $this->request('POST', '/api/auth/login', ['email' => 'tfa@example.com', 'password' => self::PASSWORD]);
        $this->assertStatus(200);
        self::assertTrue($step1['twoFactorRequired']);
        self::assertArrayNotHasKey('token', $step1);

        // Il codice già usato per l'attivazione non vale di nuovo (anti-replay). Si riusa la
        // stessa stringa: ricalcolarla potrebbe dare il codice di una finestra successiva.
        $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $codiceAttivazione]);
        $this->assertStatus(401);

        $step2 = $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $this->totp()->currentCode($setup['secret'])]);
        $this->assertStatus(200);
        self::assertNotEmpty($step2['token']);
        self::assertNotEmpty($step2['refresh_token']);

        // La challenge è monouso.
        $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $enabled['backupCodes'][0]]);
        $this->assertStatus(401);

        // Codice di recupero: funziona una sola volta.
        $again = $this->request('POST', '/api/auth/login', ['email' => 'tfa@example.com', 'password' => self::PASSWORD]);
        $this->request('POST', '/api/auth/2fa', ['challenge' => $again['challenge'], 'code' => strtoupper($enabled['backupCodes'][0])]);
        $this->assertStatus(200);
        $third = $this->request('POST', '/api/auth/login', ['email' => 'tfa@example.com', 'password' => self::PASSWORD]);
        $this->request('POST', '/api/auth/2fa', ['challenge' => $third['challenge'], 'code' => $enabled['backupCodes'][0]]);
        $this->assertStatus(401);

        self::assertCount(1, $this->auditRows('2fa.enabled', 'success'));
        self::assertGreaterThanOrEqual(2, \count($this->auditRows('auth.2fa', 'failure')));
    }

    public function testChallengeIsLockedAfterTooManyWrongCodes(): void
    {
        $user = $this->createUser('lock@example.com');
        $secret = $this->enableTwoFactor($user);

        $step1 = $this->request('POST', '/api/auth/login', ['email' => 'lock@example.com', 'password' => self::PASSWORD]);
        for ($i = 0; $i < 5; ++$i) {
            $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => '123456']);
        }
        // Anche il codice giusto ora è rifiutato: bisogna rifare il login.
        $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $this->totp()->currentCode($secret)]);
        $this->assertStatus(401);
    }

    public function testDisableRequiresValidCodeAndSecretIsEncryptedAtRest(): void
    {
        $user = $this->createUser('dis@example.com');
        $secret = $this->enableTwoFactor($user);

        $stored = $this->em()->getConnection()->fetchOne('SELECT totp_secret FROM app_user WHERE email = ?', ['dis@example.com']);
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString($secret, $stored);

        $step1 = $this->request('POST', '/api/auth/login', ['email' => 'dis@example.com', 'password' => self::PASSWORD]);
        $tokens = $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $this->totp()->currentCode($secret)]);

        $this->request('POST', '/api/me/2fa/disable', ['code' => '111111'], $tokens['token']);
        $this->assertStatus(422);

        $this->request('POST', '/api/me/2fa/disable', ['code' => $this->totp()->currentCode($secret, 1)], $tokens['token']);
        $this->assertStatus(204);

        $plain = $this->request('POST', '/api/auth/login', ['email' => 'dis@example.com', 'password' => self::PASSWORD]);
        self::assertNotEmpty($plain['token']);
    }

    /**
     * Con una sessione rubata non si possono provare codici a raffica
     * (es. per disattivare la 2FA); il limite è per utente, anche tra login diversi.
     */
    public function testWrongSecondFactorCodesAreLimitedPerUser(): void
    {
        $user = $this->createUser('brute@example.com');
        $secret = $this->enableTwoFactor($user);
        $step1 = $this->request('POST', '/api/auth/login', ['email' => 'brute@example.com', 'password' => self::PASSWORD]);
        $tokens = $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $this->totp()->currentCode($secret)]);

        for ($i = 0; $i < 5; ++$i) {
            $this->request('POST', '/api/me/2fa/disable', ['code' => '000000'], $tokens['token']);
            $this->assertStatus(422);
        }
        $this->request('POST', '/api/me/2fa/disable', ['code' => $this->totp()->currentCode($secret, 1)], $tokens['token']);
        $this->assertStatus(429);
        self::assertTrue($this->fresh($user)->isTwoFactorEnabled());

        // Vale anche per un nuovo accesso dello stesso utente, non per gli altri.
        $step1 = $this->request('POST', '/api/auth/login', ['email' => 'brute@example.com', 'password' => self::PASSWORD]);
        $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $this->totp()->currentCode($secret, 1)]);
        $this->assertStatus(429);
        $altro = $this->createUser('altro@example.com');
        $altroSecret = $this->enableTwoFactor($altro);
        $step1 = $this->request('POST', '/api/auth/login', ['email' => 'altro@example.com', 'password' => self::PASSWORD]);
        $this->request('POST', '/api/auth/2fa', ['challenge' => $step1['challenge'], 'code' => $this->totp()->currentCode($altroSecret)]);
        $this->assertStatus(200);
    }

    /** Attiva la 2FA via API e restituisce il segreto in chiaro. */
    private function enableTwoFactor(User $user): string
    {
        $token = $this->login($user);
        $setup = $this->request('POST', '/api/me/2fa/setup', [], $token);
        $this->request('POST', '/api/me/2fa/enable', ['code' => $this->totp()->currentCode($setup['secret'], -1)], $token);
        $this->assertStatus(200);

        return $setup['secret'];
    }
}
