<?php

namespace App\Shared;

/**
 * Token casuali per link e sessioni (inviti, reset, condivisioni, link
 * sicuri...): 32 byte, base64 adatto agli URL. In database se ne salva solo
 * l'hash, mai il token.
 */
final class TokenCasuale
{
    public static function nuovo(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
