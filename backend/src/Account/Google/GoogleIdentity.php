<?php

namespace App\Account\Google;

/** Dati verificati estratti dall'ID token Google. */
final class GoogleIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly string $givenName,
        public readonly string $familyName,
    ) {
    }
}
