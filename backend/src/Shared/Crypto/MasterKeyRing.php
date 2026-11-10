<?php

namespace App\Shared\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Master key dell'applicazione, letta da un file secret FUORI dal database e
 * fuori dal codice (in Docker: /run/secrets/master_key).
 *
 * Formato del file, una riga per versione (la versione più alta è quella corrente):
 *     1:<32 byte in base64>
 *     2:<32 byte in base64>      <- dopo una rotazione
 * Le versioni precedenti restano nel file finché esistono documenti che le usano
 * (vedi app:crypto:rewrap).
 *
 * Nel DB (crypto_key_version) si registra solo l'impronta di ogni versione:
 * se viene montato un file di chiave sbagliato l'applicazione si rifiuta di
 * cifrare/decifrare invece di produrre dati illeggibili.
 *
 * I prefissi "familyvault/..." dei dati associati e delle derivazioni sono
 * identificatori crittografici fissi (nome storico del progetto): cambiarli
 * renderebbe illeggibili i dati già cifrati.
 */
class MasterKeyRing
{
    /** @var array<int, string>|null versione => chiave binaria */
    private ?array $keys = null;

    public function __construct(
        #[Autowire('%env(resolve:APP_MASTER_KEY_FILE)%')]
        private readonly string $keyFile,
    ) {
    }

    public function currentVersion(): int
    {
        $keys = $this->load();

        return max(array_keys($keys));
    }

    public function key(int $version): string
    {
        return $this->load()[$version]
            ?? throw new CryptoException(sprintf('Versione %d della master key assente nel file %s.', $version, $this->keyFile));
    }

    /** @return list<int> */
    public function versions(): array
    {
        $versions = array_keys($this->load());
        sort($versions);

        return $versions;
    }

    /** Impronta non segreta della chiave (SHA-256 troncato), per riconoscerla. */
    public function fingerprint(int $version): string
    {
        return substr(hash('sha256', 'familyvault-master-key-fingerprint|'.$this->key($version)), 0, 16);
    }

    public static function generateKeyLine(int $version): string
    {
        return $version.':'.base64_encode(random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    /** @return array<int, string> */
    private function load(): array
    {
        if (null !== $this->keys) {
            return $this->keys;
        }
        if (!is_file($this->keyFile) || !is_readable($this->keyFile)) {
            throw new CryptoException(sprintf('File della master key non trovato o non leggibile: %s (vedi docs/gestione-chiavi.md).', $this->keyFile));
        }

        $keys = [];
        foreach (preg_split('/\R/', trim((string) file_get_contents($this->keyFile))) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(\d+):([A-Za-z0-9+\/=]+)$/', $line, $m)) {
                throw new CryptoException('Riga non valida nel file della master key (atteso "versione:base64").');
            }
            $key = base64_decode($m[2], true);
            if (false === $key || \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($key)) {
                throw new CryptoException(sprintf('La versione %s della master key non è di 32 byte.', $m[1]));
            }
            $keys[(int) $m[1]] = $key;
        }
        if ([] === $keys) {
            throw new CryptoException('Il file della master key non contiene chiavi.');
        }

        return $this->keys = $keys;
    }
}
