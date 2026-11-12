<?php

namespace App\Documenti;

use App\Account\Entity\User;
use App\Documenti\Entity\Categoria;
use App\Documenti\Entity\Documento;
use App\Documenti\Enum\AnteprimaStato;
use App\Documenti\Enum\OcrStatus;
use App\Documenti\Indice\IndiceDocumenti;
use App\Documenti\Message\AnteprimaDocumentoMessage;
use App\Documenti\Message\OcrDocumentoMessage;
use App\Documenti\Repository\DocumentoRepository;
use App\Import\Entity\ImportSource;
use App\Scadenze\Repository\ScadenzaRepository;
use App\Shared\Crypto\DocumentCipher;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Operazioni sui documenti e sui loro file. I PERMESSI li verifica il chiamante
 * (provider/processor) tramite il TenantPermissionVoter: qui c'è solo la logica
 * di storage, cifratura, deduplica e OCR.
 */
class DocumentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentoRepository $documenti,
        private readonly DocumentStorage $storage,
        private readonly DocumentCipher $cipher,
        private readonly UploadPolicy $policy,
        private readonly MessageBusInterface $bus,
        private readonly DocumentAudit $audit,
        private readonly LoggerInterface $logger,
        private readonly ScadenzaRepository $scadenze,
        private readonly IndiceDocumenti $indice,
    ) {
    }

    /**
     * Carica un nuovo documento.
     *
     * @param list<MembroFamiglia> $membri
     *
     * @return array{documento: Documento, duplicati: list<Documento>}
     */
    public function upload(User $owner, Tenant $tenant, Categoria $categoria, array $membri, string $contents, string $nomeFile, ?string $titolo, ?\DateTimeImmutable $dataDocumento): array
    {
        return $this->create($owner, $tenant, $categoria, $contents, $nomeFile, $titolo, static function (Documento $documento) use ($membri, $dataDocumento): void {
            $documento->replaceMembri($membri);
            $documento->setDataDocumento($dataDocumento);
        });
    }

    /**
     * Documento importato da una sorgente esterna: stesso percorso del caricamento
     * (controllo del tipo, cifratura se la categoria è sensibile, OCR in coda).
     * Il proprietario è l'utente che ha creato la sorgente.
     */
    public function importFile(ImportSource $source, Categoria $categoria, string $contents, string $nomeFile, string $fileId, string $versione): Documento
    {
        return $this->create($source->getCreatoDa(), $source->getTenant(), $categoria, $contents, $nomeFile, null, static function (Documento $documento) use ($source, $fileId, $versione): void {
            $documento->markImported($source, $fileId, $versione);
        }, ['origine' => 'import', 'import_source' => (string) $source->getId()])['documento'];
    }

    /** HMAC dell'MD5 del contenuto (vedi Documento::$hashMd5). */
    public function md5Fingerprint(string $contents, Tenant $tenant): string
    {
        return $this->md5FingerprintOfHash(md5($contents), $tenant);
    }

    /** Come md5Fingerprint, partendo da un MD5 già calcolato (es. md5Checksum di Google Drive). */
    public function md5FingerprintOfHash(string $md5Hex, Tenant $tenant): string
    {
        return $this->cipher->md5Fingerprint($md5Hex, $tenant->getId());
    }

    /**
     * @param callable(Documento): void $configure
     * @param array<string, mixed>      $auditContext
     *
     * @return array{documento: Documento, duplicati: list<Documento>}
     */
    private function create(User $owner, Tenant $tenant, Categoria $categoria, string $contents, string $nomeFile, ?string $titolo, callable $configure, array $auditContext = []): array
    {
        $mime = $this->policy->assertAcceptable($contents, $nomeFile);
        $id = Uuid::v7();
        $documento = new Documento($id, $tenant, $owner, $categoria, $titolo ?: $this->defaultTitle($nomeFile), $this->safeFileName($nomeFile, $mime));
        $configure($documento);

        // Duplicati nello stesso tenant: si confrontano sia lo SHA-256 (documenti in chiaro)
        // sia l'HMAC del tenant (documenti cifrati).
        $sha = hash('sha256', $contents);
        $hmac = $this->cipher->contentFingerprint($contents, $tenant->getId());
        $duplicati = $this->documenti->findPossibleDuplicates($tenant, [$sha, $hmac]);
        $documento->setHashMd5($this->md5Fingerprint($contents, $tenant));

        $key = DocumentStorage::keyFor($tenant->getId(), $id);
        $this->storeContent($documento, $key, $contents, $mime, $categoria->isSensibile(), $sha, $hmac);
        $documento->queueOcr(UploadPolicy::isOcrApplicable($mime));
        $documento->queueAnteprima(null !== UploadPolicy::anteprimaPer($mime));

        $this->em->persist($documento);
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            // Compensazione: niente file orfani nello storage se il salvataggio fallisce.
            $this->storage->delete($key);
            throw $e;
        }

        $this->audit->write($documento, 'documento.created', $auditContext + [
            'mime' => $mime,
            'bytes' => \strlen($contents),
            'possibili_duplicati' => array_map(static fn (Documento $d) => (string) $d->getId(), $duplicati),
        ]);
        $this->dispatchAnteprima($documento);
        // Office: l'OCR legge il PDF dell'anteprima, quindi parte dopo (AnteprimaDocumentoHandler).
        if (!UploadPolicy::isUfficio($mime)) {
            $this->dispatchOcr($documento);
        }

        return ['documento' => $documento, 'duplicati' => $duplicati];
    }

    // ---- Anteprima ------------------------------------------------------------

    /** Salva la copia per la visualizzazione accanto all'originale, cifrata se lo è il documento. */
    public function storeAnteprima(Documento $documento, string $contents, string $mime): void
    {
        $key = self::chiaveAnteprima($documento->getStorageKey());
        $this->storage->write($key, $documento->isCifrato()
            ? $this->cipher->encryptContent($contents, $this->dek($documento), $documento->getId(), 'anteprima')
            : $contents);
        $documento->completaAnteprima($key, $mime);
    }

    /**
     * Ciò che si vede nella scheda Anteprima, decifrato in memoria: l'originale
     * per PDF e immagini, altrimenti la copia. Null se la copia non è pronta.
     *
     * @return array{0: string, 1: string}|null contenuto e tipo
     */
    public function readAnteprima(Documento $documento): ?array
    {
        if (AnteprimaStato::NON_SERVE === $documento->getAnteprimaStato()) {
            // Gli ZIP non si mostrano: inutile decifrarli e mandarli per intero.
            if (!UploadPolicy::haAnteprima($documento->getMimeType())) {
                return null;
            }
            $contenuto = $this->readContent($documento);

            return 'text/plain' === $documento->getMimeType() ? [TestoSemplice::inUtf8($contenuto), 'text/plain'] : [$contenuto, $documento->getMimeType()];
        }
        $key = $documento->getAnteprimaKey();
        if (AnteprimaStato::PRONTA !== $documento->getAnteprimaStato() || null === $key) {
            return null;
        }
        $raw = $this->storage->read($key);

        return [
            $documento->isCifrato() ? $this->cipher->decryptContent($raw, $this->dek($documento), $documento->getId(), 'anteprima') : $raw,
            (string) $documento->getAnteprimaMime(),
        ];
    }

    /** Rimette in coda l'anteprima (es. dopo un fallimento o se mancava il convertitore). */
    public function rigeneraAnteprima(Documento $documento): void
    {
        if (null === UploadPolicy::anteprimaPer($documento->getMimeType())) {
            return;
        }
        $documento->queueAnteprima(true);
        if (UploadPolicy::isUfficio($documento->getMimeType()) && OcrStatus::FALLITO === $documento->getOcrStatus()) {
            $documento->queueOcr(true); // il testo si ricava dal PDF: riparte dopo
        }
        $this->em->flush();
        $this->audit->write($documento, 'documento.anteprima_richiesta');
        $this->dispatchAnteprima($documento);
    }

    public static function chiaveAnteprima(string $storageKey): string
    {
        return (preg_replace('/\.bin$/', '', $storageKey) ?? $storageKey).'-anteprima.bin';
    }

    private function dispatchAnteprima(Documento $documento): void
    {
        if (AnteprimaStato::IN_CODA === $documento->getAnteprimaStato()) {
            $this->bus->dispatch(new AnteprimaDocumentoMessage((string) $documento->getId()));
        }
    }

    /** Contenuto originale del file (decifrato in memoria se necessario). */
    public function readContent(Documento $documento): string
    {
        $raw = $this->storage->read($documento->getStorageKey());
        if (!$documento->isCifrato()) {
            return $raw;
        }

        return $this->cipher->decryptContent($raw, $this->dek($documento), $documento->getId());
    }

    public function readOcrText(Documento $documento): ?string
    {
        if (!$documento->isCifrato()) {
            return $documento->getOcrText();
        }
        $blob = $documento->getOcrTextCifrato();
        if (null === $blob) {
            return null;
        }

        return $this->cipher->decryptContent((string) base64_decode($blob, true), $this->dek($documento), $documento->getId(), 'ocr');
    }

    /** Salva il testo OCR: cifrato con la chiave del documento se il documento è cifrato. */
    public function storeOcrText(Documento $documento, string $text): void
    {
        if ($documento->isCifrato()) {
            $blob = $this->cipher->encryptContent($text, $this->dek($documento), $documento->getId(), 'ocr');
            $documento->completeOcr(null, base64_encode($blob));
        } else {
            $documento->completeOcr($text, null);
        }
    }

    /**
     * Scadenza proposta dal testo: JSON {data, tipo}, cifrato con la chiave del
     * documento se il documento è cifrato (è un dato estratto dal contenuto).
     *
     * @param array{data: string, tipo: ?string} $proposta
     */
    public function storeScadenzaProposta(Documento $documento, array $proposta): void
    {
        $json = json_encode($proposta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $documento->setScadenzaPropostaRaw($documento->isCifrato()
            ? 'enc:'.base64_encode($this->cipher->encryptContent($json, $this->dek($documento), $documento->getId(), 'scadenza'))
            : $json);
    }

    /** @return array{data: string, tipo: ?string}|null solo se ancora da decidere */
    public function readScadenzaProposta(Documento $documento): ?array
    {
        $raw = $documento->getScadenzaPropostaRaw();
        if (null === $raw || Documento::PROPOSTA_APERTA !== $documento->getScadenzaPropostaStato()) {
            return null;
        }
        if (str_starts_with($raw, 'enc:')) {
            $raw = $this->cipher->decryptContent((string) base64_decode(substr($raw, 4), true), $this->dek($documento), $documento->getId(), 'scadenza');
        }
        $data = json_decode($raw, true);

        return \is_array($data) && \is_string($data['data'] ?? null) ? ['data' => $data['data'], 'tipo' => \is_string($data['tipo'] ?? null) ? $data['tipo'] : null] : null;
    }

    /**
     * Avviso di pagamento proposto dal testo: JSON, cifrato con la chiave del
     * documento se il documento è cifrato (è un dato estratto dal contenuto).
     *
     * @param array<string, mixed> $proposta
     */
    public function storePagamentoProposta(Documento $documento, array $proposta): void
    {
        $json = json_encode($proposta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $documento->setPagamentoPropostaRaw($documento->isCifrato()
            ? 'enc:'.base64_encode($this->cipher->encryptContent($json, $this->dek($documento), $documento->getId(), 'pagamento'))
            : $json);
    }

    /** @return array<string, mixed>|null solo se ancora da decidere */
    public function readPagamentoProposta(Documento $documento): ?array
    {
        $raw = $documento->getPagamentoPropostaRaw();
        if (null === $raw || Documento::PROPOSTA_APERTA !== $documento->getPagamentoPropostaStato()) {
            return null;
        }
        if (str_starts_with($raw, 'enc:')) {
            $raw = $this->cipher->decryptContent((string) base64_decode(substr($raw, 4), true), $this->dek($documento), $documento->getId(), 'pagamento');
        }
        $data = json_decode($raw, true);

        return \is_array($data) ? $data : null;
    }

    /**
     * Cambio di categoria. Verso una categoria sensibile il documento viene cifrato
     * (file e testo OCR). Verso una non sensibile resta cifrato: la cifratura non si
     * rimuove mai in automatico.
     */
    /**
     * Verso una categoria sensibile il documento viene cifrato (file, testo,
     * anteprima) sul suo stato attuale: da chiamare prima di altre modifiche al
     * documento nella stessa richiesta, che verrebbero ricaricate dal database.
     */
    public function changeCategoria(Documento $documento, Categoria $categoria): void
    {
        if (!$categoria->isSensibile() || $documento->isCifrato()) {
            $documento->setCategoria($categoria);

            return;
        }
        $this->aggiornaBloccato($documento, function (Documento $d) use ($categoria): void {
            $d->setCategoria($categoria);
            if (!$d->isCifrato()) {
                $this->encryptExisting($d);
            }
        });
    }

    /**
     * Esegue $modifica sul documento riletto dal database e bloccato fino al
     * salvataggio. I worker (OCR, scadenza proposta, anteprima) e la cifratura al
     * cambio di categoria passano da qui: la scelta "in chiaro o cifrato" si fa
     * sullo stato attuale, mai su una copia letta prima (un OCR dura secondi).
     *
     * @param callable(Documento): void $modifica
     */
    public function aggiornaBloccato(Documento $documento, callable $modifica): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->refresh($documento, LockMode::PESSIMISTIC_WRITE);
            $modifica($documento);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    public function delete(Documento $documento): void
    {
        $key = $documento->getStorageKey();
        $anteprima = $documento->getAnteprimaKey();
        $this->audit->write($documento, 'documento.deleted', ['titolo' => $documento->getTitolo()]);
        // Le scadenze collegate restano, con l'indicazione "documento eliminato".
        $this->scadenze->markDocumentoRimosso($documento);
        $this->em->remove($documento);
        $this->em->flush();
        foreach (array_filter([$key, $anteprima]) as $k) {
            try {
                $this->storage->delete($k);
            } catch (\Throwable $e) {
                $this->logger->error('File del documento {id} non cancellato dallo storage', ['id' => (string) $documento->getId(), 'exception' => $e]);
            }
        }
    }

    public function requeueOcr(Documento $documento): void
    {
        // Office senza PDF di anteprima: si rigenera quello, e l'OCR riparte dopo.
        if (UploadPolicy::isUfficio($documento->getMimeType()) && AnteprimaStato::PRONTA !== $documento->getAnteprimaStato()) {
            $documento->queueOcr(true);
            $this->rigeneraAnteprima($documento);

            return;
        }
        $documento->queueOcr(UploadPolicy::isOcrApplicable($documento->getMimeType()));
        $this->em->flush();
        $this->audit->write($documento, 'documento.ocr_requeued');
        $this->dispatchOcr($documento);
    }

    private function encryptExisting(Documento $documento): void
    {
        $oldKey = $documento->getStorageKey();
        $contents = $this->storage->read($oldKey);
        $plainOcr = $documento->getOcrText();
        $proposta = $this->readScadenzaProposta($documento);
        $propostaPagamento = $this->readPagamentoProposta($documento);
        // La copia per l'anteprima (in chiaro finora) va cifrata con la nuova chiave.
        $anteprima = AnteprimaStato::PRONTA === $documento->getAnteprimaStato() ? $this->readAnteprima($documento) : null;
        $vecchiaAnteprima = $documento->getAnteprimaKey();
        $newKey = DocumentStorage::keyFor($documento->getTenant()->getId(), $documento->getId());
        if ($newKey === $oldKey) {
            $newKey = preg_replace('/\.bin$/', '-enc.bin', $oldKey) ?? $oldKey.'-enc';
        }

        $this->storeContent($documento, $newKey, $contents, $documento->getMimeType(), true, hash('sha256', $contents), $this->cipher->contentFingerprint($contents, $documento->getTenant()->getId()));
        if (null !== $plainOcr) {
            $this->storeOcrText($documento, $plainOcr);
        }
        if (null !== $proposta) {
            $this->storeScadenzaProposta($documento, $proposta);
        }
        if (null !== $propostaPagamento) {
            $this->storePagamentoProposta($documento, $propostaPagamento);
        }
        if (null !== $anteprima) {
            $this->storeAnteprima($documento, $anteprima[0], $anteprima[1]);
        }
        // Dati estratti e vettori rivelano il contenuto: un documento cifrato non ha indice.
        $this->indice->elimina($documento);
        $this->em->flush();
        $this->storage->delete($oldKey);
        if (null !== $vecchiaAnteprima && $vecchiaAnteprima !== $documento->getAnteprimaKey()) {
            $this->storage->delete($vecchiaAnteprima);
        }
        $this->audit->write($documento, 'documento.encrypted', ['reason' => 'categoria_sensibile']);
    }

    private function storeContent(Documento $documento, string $key, string $contents, string $mime, bool $encrypt, string $sha, string $hmac): void
    {
        if ($encrypt) {
            $dek = $this->cipher->generateDek();
            $wrapped = $this->cipher->wrapDek($dek, $documento->getTenant()->getId(), $documento->getId());
            // Il contenuto viene cifrato PRIMA di arrivare allo storage.
            $this->storage->write($key, $this->cipher->encryptContent($contents, $dek, $documento->getId()));
            sodium_memzero($dek);
            $documento->attachStoredFile($key, $mime, \strlen($contents), $hmac, Documento::HASH_HMAC, true, $wrapped['wrapped'], $wrapped['version']);
        } else {
            $this->storage->write($key, $contents);
            $documento->attachStoredFile($key, $mime, \strlen($contents), $sha, Documento::HASH_SHA256, false, null, null);
        }
    }

    private function dek(Documento $documento): string
    {
        return $this->cipher->unwrapDek((string) $documento->getEncryptedDek(), (int) $documento->getKeyVersion(), $documento->getTenant()->getId(), $documento->getId());
    }

    private function dispatchOcr(Documento $documento): void
    {
        if (\App\Documenti\Enum\OcrStatus::IN_CODA === $documento->getOcrStatus()) {
            $this->bus->dispatch(new OcrDocumentoMessage((string) $documento->getId()));
        }
    }

    private function defaultTitle(string $nomeFile): string
    {
        $title = trim((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', basename($nomeFile)));

        return mb_substr('' === $title ? 'Documento' : $title, 0, 200);
    }

    /** Nome file "pulito" per il Content-Disposition del download. */
    private function safeFileName(string $nomeFile, string $mime): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename($nomeFile)), ' ._');
        if ('' === $base) {
            $base = 'documento.'.UploadPolicy::extensionFor($mime);
        }

        return UploadPolicy::conEstensioneDelContenuto(mb_substr($base, 0, 250), $mime);
    }
}
