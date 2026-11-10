<?php

namespace App\Import;

/** Token restituiti da un provider OAuth. Vivono solo in memoria: a DB vanno cifrati (ImportTokenVault). */
final class OAuthTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?\DateTimeImmutable $scadenza,
        public readonly ?string $account = null,
    ) {
    }
}
