<?php

namespace App\Shared\Crypto;

use Symfony\Component\Uid\Uuid;

/**
 * Chiavi per tenant DERIVATE (HKDF-SHA256) dalla master key: non esistono né nel
 * codice né nel DB, si ricalcolano ogni volta da master key + id tenant + versione.
 *
 * - wrappingKey: cifra le chiavi dei singoli file (envelope encryption);
 * - fingerprintKey: HMAC del contenuto per il rilevamento duplicati dei documenti
 *   cifrati (un hash in chiaro permetterebbe a chi ha il DB di verificare se un
 *   file noto è presente).
 *
 * I prefissi "familyvault/..." dei dati associati e delle derivazioni sono
 * identificatori crittografici fissi (nome storico del progetto): cambiarli
 * renderebbe illeggibili i dati già cifrati.
 */
class TenantKeyService
{
    public function __construct(
        private readonly MasterKeyRing $keyRing,
        private readonly KeyVersionGuard $guard,
    ) {
    }

    public function currentVersion(): int
    {
        return $this->keyRing->currentVersion();
    }

    public function wrappingKey(Uuid $tenantId, int $version): string
    {
        return $this->derive($tenantId, $version, 'familyvault/tenant/dek-wrap');
    }

    public function fingerprintKey(Uuid $tenantId): string
    {
        // Sempre con la versione 1: l'HMAC dei duplicati deve restare stabile anche dopo una rotazione.
        return $this->derive($tenantId, min($this->keyRing->versions()), 'familyvault/tenant/content-hmac');
    }

    private function derive(Uuid $tenantId, int $version, string $purpose): string
    {
        $this->guard->assertValid($version);

        return hash_hkdf('sha256', $this->keyRing->key($version), \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, $purpose.'|v'.$version, $tenantId->toBinary());
    }
}
