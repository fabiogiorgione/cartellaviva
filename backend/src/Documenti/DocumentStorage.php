<?php

namespace App\Documenti;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Accesso ai file dei documenti tramite Flysystem (oggi adapter locale, domani
 * un object storage cambiando solo config/packages/flysystem.yaml).
 * Le chiavi di storage non contengono il nome originale del file né dati personali.
 */
class DocumentStorage
{
    public function __construct(
        #[Autowire(service: 'documents.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public static function keyFor(Uuid $tenantId, Uuid $documentId): string
    {
        return sprintf('%s/%s/%s.bin', $tenantId->toRfc4122(), (new \DateTimeImmutable())->format('Y/m'), $documentId->toRfc4122());
    }

    public function write(string $key, string $contents): void
    {
        $this->storage->write($key, $contents);
    }

    public function read(string $key): string
    {
        return $this->storage->read($key);
    }

    public function delete(string $key): void
    {
        if ($this->storage->fileExists($key)) {
            $this->storage->delete($key);
        }
    }

    /** Tutti i file di un tenant (le chiavi iniziano con il suo id). */
    public function deleteTenant(Uuid $tenantId): void
    {
        $this->storage->deleteDirectory($tenantId->toRfc4122());
    }

    public function exists(string $key): bool
    {
        return $this->storage->fileExists($key);
    }
}
