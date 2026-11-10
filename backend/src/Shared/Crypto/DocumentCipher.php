<?php

namespace App\Shared\Crypto;

use Symfony\Component\Uid\Uuid;

/**
 * Cifratura dei documenti sensibili con libsodium (XChaCha20-Poly1305, AEAD).
 *
 * Ogni documento ha una chiave casuale propria (DEK). Il file e il testo OCR sono
 * cifrati con la DEK; la DEK è salvata nel DB solo cifrata ("wrapped") con la
 * chiave del tenant. I dati associati (AD) legano ogni blob al suo documento e
 * tenant: un file copiato su un altro documento non si decifra.
 *
 * Formato dei blob: "FV1" | nonce (24 byte) | ciphertext+tag.
 *
 * I prefissi "familyvault/..." dei dati associati e delle derivazioni sono
 * identificatori crittografici fissi (nome storico del progetto): cambiarli
 * renderebbe illeggibili i dati già cifrati.
 */
class DocumentCipher
{
    private const MAGIC = 'FV1';
    private const NONCE = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    public function __construct(private readonly TenantKeyService $tenantKeys)
    {
    }

    public function generateDek(): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
    }

    /** @return array{wrapped: string, version: int} DEK cifrata (base64) e versione della chiave */
    public function wrapDek(string $dek, Uuid $tenantId, Uuid $documentId, ?int $version = null): array
    {
        $version ??= $this->tenantKeys->currentVersion();
        $blob = $this->seal($dek, $this->tenantKeys->wrappingKey($tenantId, $version), $this->dekAd($tenantId, $documentId, $version));

        return ['wrapped' => base64_encode($blob), 'version' => $version];
    }

    public function unwrapDek(string $wrapped, int $version, Uuid $tenantId, Uuid $documentId): string
    {
        $blob = base64_decode($wrapped, true);
        if (false === $blob) {
            throw new CryptoException('Chiave del documento corrotta.');
        }

        return $this->open($blob, $this->tenantKeys->wrappingKey($tenantId, $version), $this->dekAd($tenantId, $documentId, $version));
    }

    public function encryptContent(string $plaintext, string $dek, Uuid $documentId, string $purpose = 'file'): string
    {
        return $this->seal($plaintext, $dek, 'familyvault/document/'.$purpose.'|'.$documentId->toRfc4122());
    }

    public function decryptContent(string $blob, string $dek, Uuid $documentId, string $purpose = 'file'): string
    {
        return $this->open($blob, $dek, 'familyvault/document/'.$purpose.'|'.$documentId->toRfc4122());
    }

    /** HMAC-SHA256 del contenuto con la chiave del tenant (dedup dei documenti cifrati). */
    public function contentFingerprint(string $plaintext, Uuid $tenantId): string
    {
        return hash_hmac('sha256', $plaintext, $this->tenantKeys->fingerprintKey($tenantId));
    }

    /**
     * HMAC dell'MD5 del contenuto (hex, come lo restituisce Google Drive): riconosce
     * un file già presente senza scaricarlo e senza salvare l'MD5 in chiaro.
     */
    public function md5Fingerprint(string $md5Hex, Uuid $tenantId): string
    {
        return hash_hmac('sha256', 'md5|'.strtolower($md5Hex), $this->tenantKeys->fingerprintKey($tenantId));
    }

    /**
     * Segreti delle sorgenti di import (token OAuth): stessa cifratura dei documenti,
     * con dati associati propri (un token non si può spacciare per un file o per
     * il token di un'altra sorgente).
     */
    public function encryptSecret(string $plaintext, string $dek, Uuid $ownerId, string $purpose): string
    {
        return $this->seal($plaintext, $dek, 'familyvault/secret/'.$purpose.'|'.$ownerId->toRfc4122());
    }

    public function decryptSecret(string $blob, string $dek, Uuid $ownerId, string $purpose): string
    {
        return $this->open($blob, $dek, 'familyvault/secret/'.$purpose.'|'.$ownerId->toRfc4122());
    }

    private function dekAd(Uuid $tenantId, Uuid $documentId, int $version): string
    {
        return 'familyvault/dek|'.$tenantId->toRfc4122().'|'.$documentId->toRfc4122().'|v'.$version;
    }

    private function seal(string $plaintext, string $key, string $ad): string
    {
        $nonce = random_bytes(self::NONCE);

        return self::MAGIC.$nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $ad, $nonce, $key);
    }

    private function open(string $blob, string $key, string $ad): string
    {
        if (!str_starts_with($blob, self::MAGIC) || \strlen($blob) < \strlen(self::MAGIC) + self::NONCE + 16) {
            throw new CryptoException('Contenuto cifrato non riconosciuto.');
        }
        $nonce = substr($blob, \strlen(self::MAGIC), self::NONCE);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($blob, \strlen(self::MAGIC) + self::NONCE), $ad, $nonce, $key);
        if (false === $plain) {
            throw new CryptoException('Decifratura fallita: chiave errata o contenuto manomesso.');
        }

        return $plain;
    }
}
