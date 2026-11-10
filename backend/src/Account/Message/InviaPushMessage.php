<?php

namespace App\Account\Message;

/** Notifica push a tutti i telefoni di un utente (nel worker). */
final class InviaPushMessage
{
    public function __construct(
        public readonly string $userId,
        public readonly string $titolo,
        public readonly string $testo,
        public readonly string $route,
    ) {
    }
}
