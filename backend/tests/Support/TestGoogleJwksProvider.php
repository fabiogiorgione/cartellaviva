<?php

namespace App\Tests\Support;

use App\Account\Google\GoogleJwksProviderInterface;
use Firebase\JWT\JWT;

/**
 * Sostituisce le chiavi pubbliche di Google nei test: una coppia RSA generata
 * in memoria firma ID token con i claim voluti (vedi sign()).
 */
final class TestGoogleJwksProvider implements GoogleJwksProviderInterface
{
    public const KID = 'test-key';
    public const WEB_CLIENT_ID = 'test-web-client.apps.googleusercontent.com';

    private static ?\OpenSSLAsymmetricKey $key = null;

    public function getKeySet(): array
    {
        $details = openssl_pkey_get_details(self::key());

        return ['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => self::KID,
            'n' => self::b64url($details['rsa']['n']),
            'e' => self::b64url($details['rsa']['e']),
        ]]];
    }

    /** @param array<string, mixed> $overrides */
    public static function sign(string $sub, string $email, array $overrides = []): string
    {
        $claims = $overrides + [
            'iss' => 'https://accounts.google.com',
            'aud' => self::WEB_CLIENT_ID,
            'sub' => $sub,
            'email' => $email,
            'email_verified' => true,
            'given_name' => 'Anna',
            'family_name' => 'Google',
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        return JWT::encode($claims, self::key(), 'RS256', self::KID);
    }

    private static function key(): \OpenSSLAsymmetricKey
    {
        return self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
