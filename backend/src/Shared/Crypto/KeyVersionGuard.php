<?php

namespace App\Shared\Crypto;

use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Confronta l'impronta delle chiavi del file con quelle registrate a DB.
 * La prima volta che una versione viene usata la registra (trust on first use,
 * con riga nell'audit log); da quel momento un file diverso viene rifiutato.
 */
class KeyVersionGuard implements ResetInterface
{
    /** @var array<int, true> */
    private array $verified = [];

    public function __construct(
        private readonly MasterKeyRing $keyRing,
        private readonly Connection $connection,
        private readonly AuditLogger $audit,
    ) {
    }

    public function assertValid(int $version): void
    {
        if (isset($this->verified[$version])) {
            return;
        }
        $fingerprint = $this->keyRing->fingerprint($version);
        $registered = $this->connection->fetchOne('SELECT fingerprint FROM crypto_key_version WHERE version = ?', [$version]);

        if (false === $registered) {
            try {
                $this->connection->insert('crypto_key_version', [
                    'version' => $version,
                    'fingerprint' => $fingerprint,
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
                $this->audit->log('crypto.key_registered', AuditOutcome::SUCCESS, context: ['version' => $version, 'fingerprint' => $fingerprint]);
                $registered = $fingerprint;
            } catch (UniqueConstraintViolationException) {
                // Registrata in parallelo da un altro processo (API o worker): la si rilegge.
                $registered = $this->connection->fetchOne('SELECT fingerprint FROM crypto_key_version WHERE version = ?', [$version]);
            }
        }
        if (!hash_equals((string) $registered, $fingerprint)) {
            $this->audit->log('crypto.key_mismatch', AuditOutcome::FAILURE, context: ['version' => $version, 'expected' => $registered, 'found' => $fingerprint]);
            throw new CryptoException(sprintf(
                'La master key v%d montata (impronta %s) non è quella con cui sono stati cifrati i dati (impronta %s). Controlla il secret: NON cifrare nulla finché non è risolto.',
                $version, $fingerprint, $registered,
            ));
        }
        $this->verified[$version] = true;
    }

    public function reset(): void
    {
        $this->verified = [];
    }
}
