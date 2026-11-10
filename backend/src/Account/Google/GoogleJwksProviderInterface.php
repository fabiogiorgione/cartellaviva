<?php

namespace App\Account\Google;

interface GoogleJwksProviderInterface
{
    /**
     * Chiavi pubbliche con cui Google firma gli ID token (formato JWKS).
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function getKeySet(): array;
}
