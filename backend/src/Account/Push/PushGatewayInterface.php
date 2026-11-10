<?php

namespace App\Account\Push;

interface PushGatewayInterface
{
    public const OK = 'ok';
    /** Il token non vale più (app disinstallata, token ruotato): va eliminato. */
    public const TOKEN_NON_VALIDO = 'token_non_valido';

    /** False se mancano FIREBASE_PROJECT_ID / FIREBASE_CREDENTIALS. */
    public function isEnabled(): bool;

    /**
     * @param array<string, string> $data
     *
     * @return self::OK|self::TOKEN_NON_VALIDO
     *
     * @throws \RuntimeException per errori temporanei (Messenger ritenta)
     */
    public function invia(string $token, string $titolo, string $testo, array $data): string;
}
