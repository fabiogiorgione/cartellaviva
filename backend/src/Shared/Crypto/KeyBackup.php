<?php

namespace App\Shared\Crypto;

/**
 * Backup della master key protetto da passphrase (Argon2id + XSalsa20-Poly1305).
 * Il backup è testo ASCII: si può stampare, salvare in un password manager o su
 * una chiavetta offline. Contiene anche le impronte per verificarne il ripristino.
 *
 * Intestazione e contesti "FAMILYVAULT"/"familyvault" sono fissi (nome storico
 * del progetto): cambiarli renderebbe illeggibili i backup già fatti.
 */
final class KeyBackup
{
    private const HEADER = '-----BEGIN FAMILYVAULT MASTER KEY BACKUP-----';
    private const FOOTER = '-----END FAMILYVAULT MASTER KEY BACKUP-----';
    private const MIN_PASSPHRASE = 16;

    public static function export(string $keyFileContents, string $passphrase, array $fingerprints): string
    {
        if (mb_strlen($passphrase) < self::MIN_PASSPHRASE) {
            throw new CryptoException(sprintf('La passphrase deve avere almeno %d caratteri.', self::MIN_PASSPHRASE));
        }
        $salt = random_bytes(\SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = self::derive($passphrase, $salt);
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = base64_encode($salt.$nonce.sodium_crypto_secretbox($keyFileContents, $nonce, $key));
        sodium_memzero($key);

        $lines = [self::HEADER, 'Formato: 1', 'Creato: '.(new \DateTimeImmutable())->format(\DATE_ATOM)];
        foreach ($fingerprints as $version => $fp) {
            $lines[] = sprintf('Impronta v%d: %s', $version, $fp);
        }
        $lines[] = '';

        return implode("\n", array_merge($lines, str_split($payload, 64), [self::FOOTER, '']));
    }

    public static function import(string $backup, string $passphrase): string
    {
        if (!preg_match('/'.preg_quote(self::HEADER, '/').'(.*?)'.preg_quote(self::FOOTER, '/').'/s', $backup, $m)) {
            throw new CryptoException('Backup non riconosciuto.');
        }
        $body = preg_replace('/^(Formato|Creato|Impronta)[^\n]*\n/m', '', $m[1]) ?? '';
        $raw = base64_decode((string) preg_replace('/\s+/', '', $body), true);
        if (false === $raw || \strlen($raw) < \SODIUM_CRYPTO_PWHASH_SALTBYTES + \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16) {
            throw new CryptoException('Backup corrotto.');
        }
        $salt = substr($raw, 0, \SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = substr($raw, \SODIUM_CRYPTO_PWHASH_SALTBYTES, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = self::derive($passphrase, $salt);
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_PWHASH_SALTBYTES + \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        sodium_memzero($key);
        if (false === $plain) {
            throw new CryptoException('Passphrase errata o backup manomesso.');
        }

        return $plain;
    }

    private static function derive(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            \SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
