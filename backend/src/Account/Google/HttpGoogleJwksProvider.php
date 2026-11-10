<?php

namespace App\Account\Google;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scarica le chiavi pubbliche di Google e le tiene in cache per il tempo
 * indicato da Cache-Control (Google le ruota periodicamente).
 */
final class HttpGoogleJwksProvider implements GoogleJwksProviderInterface
{
    private const URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    public function getKeySet(): array
    {
        return $this->cache->get('google_oauth2_jwks', function (ItemInterface $item): array {
            $response = $this->httpClient->request('GET', self::URL, ['timeout' => 5]);
            $maxAge = 3600;
            if (preg_match('/max-age=(\d+)/', $response->getHeaders()['cache-control'][0] ?? '', $m)) {
                $maxAge = (int) $m[1];
            }
            $item->expiresAfter($maxAge);

            return $response->toArray();
        });
    }
}
