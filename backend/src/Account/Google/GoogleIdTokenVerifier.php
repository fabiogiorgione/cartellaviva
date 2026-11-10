<?php

namespace App\Account\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verifica lato server dell'ID token ottenuto dall'app con Google Sign-In:
 * firma (chiavi pubbliche Google), emittente, destinatario (uno dei nostri
 * client ID: web, Android, iOS), scadenza ed email verificata.
 * Il client non viene mai creduto sulla parola: conta solo il token firmato.
 */
final class GoogleIdTokenVerifier
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** @var list<string> */
    private readonly array $clientIds;

    /** @param list<string|null> $clientIds */
    public function __construct(
        private readonly GoogleJwksProviderInterface $jwks,
        #[Autowire('%env(csv:GOOGLE_CLIENT_IDS)%')]
        array $clientIds,
    ) {
        $this->clientIds = array_values(array_filter(array_map(static fn ($id) => trim((string) $id), $clientIds)));
    }

    public function isConfigured(): bool
    {
        return [] !== $this->clientIds;
    }

    /** @throws InvalidGoogleTokenException */
    public function verify(string $idToken): GoogleIdentity
    {
        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->jwks->getKeySet(), 'RS256'));
        } catch (\Throwable $e) {
            throw new InvalidGoogleTokenException('Token Google non valido: '.$e->getMessage(), previous: $e);
        }

        if (!\in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new InvalidGoogleTokenException('Emittente del token non valido.');
        }
        if (!\in_array($claims['aud'] ?? null, $this->clientIds, true)) {
            throw new InvalidGoogleTokenException('Il token non è destinato a questa applicazione.');
        }
        if (true !== ($claims['email_verified'] ?? false) || !\is_string($claims['email'] ?? null) || !\is_string($claims['sub'] ?? null)) {
            throw new InvalidGoogleTokenException('L\'account Google non ha un\'email verificata.');
        }

        return new GoogleIdentity(
            subject: $claims['sub'],
            email: $claims['email'],
            givenName: (string) ($claims['given_name'] ?? ''),
            familyName: (string) ($claims['family_name'] ?? ''),
        );
    }
}
