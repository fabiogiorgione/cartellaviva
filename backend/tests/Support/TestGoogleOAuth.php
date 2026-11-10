<?php

namespace App\Tests\Support;

use App\Import\Exception\ImportException;
use App\Import\OAuthTokens;
use App\Import\Provider\GoogleDrive\GoogleOAuthInterface;

/** OAuth di Google finto: nessuna chiamata di rete. */
final class TestGoogleOAuth implements GoogleOAuthInterface
{
    public const GOOD_CODE = 'codice-valido';

    public bool $configured = true;
    public bool $refreshRevoked = false;
    public int $refreshCount = 0;
    public ?string $lastState = null;

    /** @var list<string> token revocati */
    public array $revoked = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function authorizationUrl(string $state): string
    {
        $this->lastState = $state;

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['state' => $state, 'scope' => self::SCOPE, 'access_type' => 'offline', 'prompt' => 'consent']);
    }

    public function exchangeCode(string $code): OAuthTokens
    {
        if (self::GOOD_CODE !== $code) {
            throw new ImportException('Google ha rifiutato il collegamento: riprova.');
        }

        return new OAuthTokens('access-token-1', 'refresh-token-segreto', new \DateTimeImmutable('+1 hour'), 'famiglia@gmail.com');
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        if ($this->refreshRevoked || 'refresh-token-segreto' !== $refreshToken) {
            throw new ImportException('L\'accesso a Google Drive è stato revocato o è scaduto: ricollega l\'account.');
        }
        ++$this->refreshCount;

        return new OAuthTokens('access-token-'.($this->refreshCount + 1), null, new \DateTimeImmutable('+1 hour'));
    }

    public function revoke(string $token): void
    {
        $this->revoked[] = $token;
    }
}
