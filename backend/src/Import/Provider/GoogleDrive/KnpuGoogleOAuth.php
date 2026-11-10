<?php

namespace App\Import\Provider\GoogleDrive;

use App\Import\Exception\ImportException;
use App\Import\OAuthTokens;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * OAuth di Google tramite KnpUOAuth2ClientBundle (client "google_drive" in
 * config/packages/knpu_oauth2_client.yaml).
 *
 * Si usa direttamente il provider League, senza la sessione di KnpU: l'API è
 * stateless e lo "state" è gestito da ImportSourceService (monouso, a DB).
 * Il redirect URI è sempre GOOGLE_OAUTH_REDIRECT_URI, identico a quello
 * registrato nella Google Cloud Console.
 */
final class KnpuGoogleOAuth implements GoogleOAuthInterface
{
    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'GOOGLE_OAUTH_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'GOOGLE_OAUTH_CLIENT_SECRET')]
        private readonly string $clientSecret,
        #[Autowire(env: 'GOOGLE_OAUTH_REDIRECT_URI')]
        private readonly string $redirectUri,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->clientId) && '' !== trim($this->clientSecret) && '' !== trim($this->redirectUri);
    }

    public function authorizationUrl(string $state): string
    {
        return $this->provider()->getAuthorizationUrl([
            'state' => $state,
            'scope' => [self::SCOPE],
            'redirect_uri' => $this->redirectUri,
            // Senza offline + consent Google non restituisce il refresh token.
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    public function exchangeCode(string $code): OAuthTokens
    {
        $token = $this->request('authorization_code', ['code' => $code, 'redirect_uri' => $this->redirectUri]);

        // Con il consenso granulare l'utente può togliere la spunta su Drive.
        $granted = explode(' ', (string) ($token->getValues()['scope'] ?? ''));
        if (!\in_array(self::SCOPE, $granted, true)) {
            throw new ImportException('Non è stato concesso l\'accesso in lettura a Google Drive: ricollega l\'account e lascia selezionato il permesso su Drive.');
        }

        return new OAuthTokens($token->getToken(), $token->getRefreshToken(), $this->expiry($token), $this->emailFromIdToken($token));
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        $token = $this->request('refresh_token', ['refresh_token' => $refreshToken]);

        return new OAuthTokens($token->getToken(), $token->getRefreshToken(), $this->expiry($token));
    }

    public function revoke(string $token): void
    {
        try {
            $this->provider()->getHttpClient()->request('POST', 'https://oauth2.googleapis.com/revoke', ['form_params' => ['token' => $token], 'timeout' => 10]);
        } catch (\Throwable $e) {
            $this->logger->warning('OAuth Google: revoca del token fallita: {reason}', ['reason' => $e->getMessage()]);
        }
    }

    /** @param array<string, string> $params */
    private function request(string $grant, array $params): AccessToken
    {
        try {
            $token = $this->provider()->getAccessToken($grant, $params);
        } catch (\Throwable $e) {
            // Il dettaglio va solo nel log (senza parametri: contengono codici e token).
            $this->logger->warning('OAuth Google: richiesta {grant} fallita: {reason}', ['grant' => $grant, 'reason' => $e->getMessage()]);
            throw new ImportException('refresh_token' === $grant
                ? 'L\'accesso a Google Drive è stato revocato o è scaduto: ricollega l\'account.'
                : 'Google ha rifiutato il collegamento: riprova.', 0, $e);
        }
        \assert($token instanceof AccessToken);

        return $token;
    }

    private function provider(): AbstractProvider
    {
        return $this->clients->getClient('google_drive')->getOAuth2Provider();
    }

    private function expiry(AccessToken $token): ?\DateTimeImmutable
    {
        return null !== $token->getExpires() ? (new \DateTimeImmutable())->setTimestamp($token->getExpires()) : null;
    }

    /**
     * Email dell'account, solo da mostrare all'utente. L'ID token arriva direttamente
     * dal token endpoint di Google via TLS, quindi basta leggerne il payload.
     */
    private function emailFromIdToken(AccessToken $token): ?string
    {
        $idToken = (string) ($token->getValues()['id_token'] ?? '');
        $parts = explode('.', $idToken);
        if (3 !== \count($parts)) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return \is_array($payload) && \is_string($payload['email'] ?? null) ? $payload['email'] : null;
    }
}
