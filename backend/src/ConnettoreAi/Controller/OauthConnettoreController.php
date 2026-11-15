<?php

namespace App\ConnettoreAi\Controller;

use App\ConnettoreAi\ConnettoriAi;
use App\ConnettoreAi\ErroreOAuth;
use App\Shared\Errore\DatiNonValidi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Abbinamento del connettore AI (OAuth 2.0 Device Authorization Grant, RFC 8628).
 * Rotte pubbliche: le credenziali sono il device_code e il refresh token.
 * Accetta form (come la RFC) o JSON.
 */
final class OauthConnettoreController extends AbstractController
{
    public function __construct(
        private readonly ConnettoriAi $connettori,
        #[Autowire(service: 'limiter.connettore_device')]
        private readonly RateLimiterFactoryInterface $limiteDevice,
        #[Autowire('%env(default::FRONTEND_URL)%')]
        private readonly ?string $frontendUrl = null,
    ) {
    }

    #[Route('/api/ai-connector-plugin/oauth/device', name: 'api_connettore_device', methods: ['POST'])]
    public function device(Request $request): JsonResponse
    {
        $p = self::parametri($request);
        if (ConnettoriAi::CLIENT_ID !== ($p['client_id'] ?? null)) {
            return self::errore('invalid_client', 'client_id sconosciuto.', 401);
        }
        if (!$this->limiteDevice->create($request->getClientIp() ?? 'ip')->consume()->isAccepted()) {
            return self::errore('slow_down', 'Troppe richieste di collegamento: riprova tra un\'ora.', 429);
        }
        try {
            $r = $this->connettori->richiedi(\is_string($p['nome'] ?? null) ? $p['nome'] : 'Mac');
        } catch (DatiNonValidi $e) {
            return self::errore('invalid_request', $e->getMessage());
        }
        $base = rtrim($this->frontendUrl ?: $request->getSchemeAndHttpHost(), '/').'/collega-mac';

        return new JsonResponse($r + ['verification_uri' => $base, 'verification_uri_complete' => $base.'?codice='.$r['user_code']], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/ai-connector-plugin/oauth/token', name: 'api_connettore_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        $p = self::parametri($request);
        if (ConnettoriAi::CLIENT_ID !== ($p['client_id'] ?? null)) {
            return self::errore('invalid_client', 'client_id sconosciuto.', 401);
        }
        try {
            $token = match ($p['grant_type'] ?? null) {
                'urn:ietf:params:oauth:grant-type:device_code' => $this->connettori->scambia(\is_string($p['device_code'] ?? null) ? $p['device_code'] : throw new ErroreOAuth('invalid_request', 'Manca device_code.')),
                'refresh_token' => $this->connettori->rinnova(\is_string($p['refresh_token'] ?? null) ? $p['refresh_token'] : throw new ErroreOAuth('invalid_request', 'Manca refresh_token.')),
                default => throw new ErroreOAuth('unsupported_grant_type', 'grant_type non supportato.'),
            };
        } catch (ErroreOAuth $e) {
            return self::errore($e->codice, $e->getMessage());
        }

        return new JsonResponse($token, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** @return array<string, mixed> */
    private static function parametri(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $d = json_decode($request->getContent(), true);

            return \is_array($d) ? $d : [];
        }

        return $request->request->all();
    }

    private static function errore(string $codice, string $descrizione, int $stato = 400): JsonResponse
    {
        return new JsonResponse(['error' => $codice, 'error_description' => $descrizione], $stato, ['Cache-Control' => 'no-store']);
    }
}
