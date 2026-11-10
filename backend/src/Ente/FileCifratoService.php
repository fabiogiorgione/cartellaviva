<?php

namespace App\Ente;

use App\Documenti\Anteprima\AnteprimaException;
use App\Documenti\Anteprima\GeneratoreAnteprima;
use App\Documenti\DocumentService;
use App\Documenti\DocumentStorage;
use App\Documenti\TestoSemplice;
use App\Documenti\UploadPolicy;
use App\Ente\Entity\FileCifrato;
use App\Shared\Crypto\DocumentCipher;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * File dell'ente sempre cifrati (stessa cifratura dei documenti sensibili:
 * DEK propria, avvolta con la chiave del tenant ente). Il contenuto in chiaro
 * esiste solo in memoria.
 */
final class FileCifratoService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentStorage $storage,
        private readonly DocumentCipher $cipher,
        private readonly UploadPolicy $policy,
        private readonly GeneratoreAnteprima $generatore,
    ) {
    }

    /** Da salvare con flush del chiamante; in caso di errore il file va rimosso con [delete]. */
    public function store(Tenant $tenant, string $contents, string $nomeFile, bool $soloPdf = false): FileCifrato
    {
        $mime = $this->policy->assertAcceptable($contents, $nomeFile);
        if ($soloPdf && 'application/pdf' !== $mime) {
            throw new DatiNonValidi('Serve un PDF.');
        }
        $id = Uuid::v7();
        $key = sprintf('%s/enti/%s.bin', $tenant->getId()->toRfc4122(), $id->toRfc4122());
        $dek = $this->cipher->generateDek();
        $wrapped = $this->cipher->wrapDek($dek, $tenant->getId(), $id);
        $this->storage->write($key, $this->cipher->encryptContent($contents, $dek, $id));
        sodium_memzero($dek);

        $nome = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename($nomeFile)), ' ._') ?: 'documento.'.UploadPolicy::extensionFor($mime);
        $file = new FileCifrato($id, $tenant, $key, $mime, \strlen($contents), UploadPolicy::conEstensioneDelContenuto(mb_substr($nome, 0, 250), $mime), $wrapped['wrapped'], $wrapped['version']);
        $this->em->persist($file);

        return $file;
    }

    public function read(FileCifrato $file): string
    {
        $dek = $this->cipher->unwrapDek($file->getEncryptedDek(), $file->getKeyVersion(), $file->getTenant()->getId(), $file->getId());
        try {
            return $this->cipher->decryptContent($this->storage->read($file->getStorageKey()), $dek, $file->getId());
        } finally {
            sodium_memzero($dek);
        }
    }

    public function delete(FileCifrato $file): void
    {
        $this->storage->delete($file->getStorageKey());
        if (null !== $file->getAnteprimaKey()) {
            $this->storage->delete($file->getAnteprimaKey());
        }
    }

    /**
     * Ciò che si vede nell'anteprima: l'originale per PDF e immagini, altrimenti
     * la copia (Office → PDF, HEIC → JPEG), creata alla prima apertura e salvata
     * cifrata con la stessa DEK. Così vale anche per le consegne già fatte.
     *
     * @return array{0: string, 1: string} contenuto e tipo
     *
     * @throws AnteprimaException se la copia non si può creare (es. convertitore non configurato)
     */
    public function readAnteprima(FileCifrato $file): array
    {
        if (!UploadPolicy::haAnteprima($file->getMimeType())) {
            throw new AnteprimaException(UploadPolicy::MOTIVO_SENZA_ANTEPRIMA);
        }
        if ('text/plain' === $file->getMimeType()) {
            return [TestoSemplice::inUtf8($this->read($file)), 'text/plain'];
        }
        if (null === UploadPolicy::anteprimaPer($file->getMimeType())) {
            return [$this->read($file), $file->getMimeType()];
        }
        $dek = $this->cipher->unwrapDek($file->getEncryptedDek(), $file->getKeyVersion(), $file->getTenant()->getId(), $file->getId());
        try {
            if (null !== ($key = $file->getAnteprimaKey())) {
                return [$this->cipher->decryptContent($this->storage->read($key), $dek, $file->getId(), 'anteprima'), (string) $file->getAnteprimaMime()];
            }
            [$copia, $mime] = $this->generatore->genera($this->read($file), $file->getMimeType(), $file->getNomeFile());
            $key = DocumentService::chiaveAnteprima($file->getStorageKey());
            $this->storage->write($key, $this->cipher->encryptContent($copia, $dek, $file->getId(), 'anteprima'));
            $file->setAnteprima($key, $mime);
            $this->em->flush();

            return [$copia, $mime];
        } finally {
            sodium_memzero($dek);
        }
    }
}
