<?php

namespace App\Tests\Support;

use App\Account\Push\PushGatewayInterface;

/** Gateway push dei test: registra gli invii; i token in $nonValidi risultano scaduti. */
final class FakePushGateway implements PushGatewayInterface
{
    public bool $enabled = true;

    /** @var list<array{token: string, titolo: string, testo: string, data: array<string, string>}> */
    public array $inviate = [];

    /** @var list<string> */
    public array $nonValidi = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function invia(string $token, string $titolo, string $testo, array $data): string
    {
        if (\in_array($token, $this->nonValidi, true)) {
            return self::TOKEN_NON_VALIDO;
        }
        $this->inviate[] = ['token' => $token, 'titolo' => $titolo, 'testo' => $testo, 'data' => $data];

        return self::OK;
    }
}
