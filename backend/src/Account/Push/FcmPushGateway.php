<?php

namespace App\Account\Push;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Firebase Cloud Messaging, API HTTP v1, con un service account.
 *
 * Configurazione SOLO da env (mai nel codice né a DB):
 * - FIREBASE_PROJECT_ID: id del progetto Firebase;
 * - FIREBASE_CREDENTIALS: percorso del JSON del service account, montato
 *   come secret (non nell'immagine, non nel repository).
 * Se mancano le push sono "non configurate": nessun errore, solo email.
 *
 * L'access token OAuth (1 ora) si ottiene firmando un JWT RS256 con la chiave
 * del service account e resta solo nella cache, mai a DB.
 */
final class FcmPushGateway implements PushGatewayInterface
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        #[Autowire(env: 'FIREBASE_PROJECT_ID')]
        private readonly string $projectId,
        #[Autowire(env: 'FIREBASE_CREDENTIALS')]
        private readonly string $credentialsPath,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->projectId) && '' !== trim($this->credentialsPath) && is_readable($this->credentialsPath);
    }

    public function invia(string $token, string $titolo, string $testo, array $data): string
    {
        $response = $this->httpClient->request('POST', sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', rawurlencode($this->projectId)), [
            'auth_bearer' => $this->accessToken(),
            'timeout' => 15,
            'json' => ['message' => [
                'token' => $token,
                'notification' => ['title' => $titolo, 'body' => $testo],
                'data' => (object) $data,
                'android' => ['priority' => 'high'],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]],
        ]);
        $status = $response->getStatusCode();
        if (200 === $status) {
            return self::OK;
        }
        $errore = $response->toArray(false)['error'] ?? [];
        $dettagli = json_encode($errore['details'] ?? []);
        if (404 === $status || str_contains((string) $dettagli, 'UNREGISTERED') || (400 === $status && 'INVALID_ARGUMENT' === ($errore['status'] ?? null))) {
            return self::TOKEN_NON_VALIDO;
        }

        throw new \RuntimeException(sprintf('FCM ha risposto HTTP %d: %s', $status, mb_substr((string) ($errore['message'] ?? ''), 0, 200)));
    }

    private function accessToken(): string
    {
        return $this->cache->get('fcm_access_token_'.md5($this->projectId), function (ItemInterface $item): string {
            $item->expiresAfter(3000); // il token dura 3600 s
            $sa = json_decode((string) file_get_contents($this->credentialsPath), true);
            if (!\is_array($sa) || !\is_string($sa['client_email'] ?? null) || !\is_string($sa['private_key'] ?? null)) {
                throw new \RuntimeException('FIREBASE_CREDENTIALS non è il JSON di un service account.');
            }
            $ora = time();
            $jwt = self::jwt(['iss' => $sa['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $ora, 'exp' => $ora + 3600], $sa['private_key']);
            $risposta = $this->httpClient->request('POST', self::TOKEN_URL, ['body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt], 'timeout' => 15]);
            $token = $risposta->toArray(false)['access_token'] ?? null;
            if (!\is_string($token)) {
                throw new \RuntimeException(sprintf('Token OAuth di Google non ottenuto (HTTP %d).', $risposta->getStatusCode()));
            }

            return $token;
        });
    }

    /** @param array<string, mixed> $claims */
    public static function jwt(array $claims, string $privateKeyPem): string
    {
        $b64 = static fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode($claims));
        if (!openssl_sign($input, $firma, $privateKeyPem, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Firma del JWT per FCM non riuscita.');
        }

        return $input.'.'.$b64($firma);
    }
}
