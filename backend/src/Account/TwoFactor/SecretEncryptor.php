<?php

namespace App\Account\TwoFactor;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cifratura simmetrica (libsodium secretbox) dei segreti TOTP a riposo:
 * una copia del database non basta a generare codici validi.
 * La chiave (32 byte, base64) arriva da APP_ENCRYPTION_KEY e in produzione va
 * gestita come secret, separata dal database.
 */
final class SecretEncryptor
{
    private readonly string $key;

    public function __construct(#[Autowire('%env(APP_ENCRYPTION_KEY)%')] string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException('APP_ENCRYPTION_KEY deve essere una chiave di 32 byte in base64 (php -r "echo base64_encode(random_bytes(32));").');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, 'v1:')) {
            throw new \RuntimeException('Formato del segreto cifrato non riconosciuto.');
        }
        $raw = base64_decode(substr($ciphertext, 3), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Segreto cifrato corrotto.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if (false === $plain) {
            throw new \RuntimeException('Impossibile decifrare il segreto (chiave errata?).');
        }

        return $plain;
    }
}
