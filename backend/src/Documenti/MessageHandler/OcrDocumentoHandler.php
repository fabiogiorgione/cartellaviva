<?php

namespace App\Documenti\MessageHandler;

use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Enum\OcrStatus;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Documenti\Message\OcrDocumentoMessage;
use App\Documenti\Ocr\OcrEngineInterface;
use App\Documenti\UploadPolicy;
use App\Shared\Audit\AuditLogger;
use App\Shared\Doctrine\PerId;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Worker OCR (servizio "worker" del docker-compose).
 * 1. carica il documento; 2. legge il file e lo decifra SOLO in memoria;
 * 3. esegue Tesseract; 4. salva il testo (cifrato se il documento è cifrato);
 * 5. emette DocumentoOcrCompletatoEvent (aggancio per classificazione ecc.).
 * Ogni errore porta a ocr_status = fallito con il motivo salvato e loggato:
 * nessun fallimento silenzioso. L'OCR si può rilanciare dall'API.
 */
#[AsMessageHandler]
final class OcrDocumentoHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly OcrEngineInterface $engine,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $audit,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(OcrDocumentoMessage $message): void
    {
        $documento = PerId::trova($this->em, Documento::class, $message->documentoId);
        if (null === $documento) {
            $this->logger->warning('OCR: documento {id} non trovato (cancellato prima dell\'elaborazione?)', ['id' => $message->documentoId]);

            return;
        }
        if (OcrStatus::IN_CODA !== $documento->getOcrStatus()) {
            return; // già elaborato (es. messaggio duplicato)
        }

        try {
            // Office: Tesseract non legge DOCX & co., si usa il PDF dell'anteprima.
            if (UploadPolicy::isUfficio($documento->getMimeType())) {
                [$contents, $mime] = $this->documents->readAnteprima($documento) ?? throw new \RuntimeException('PDF di anteprima non ancora pronto.');
            } else {
                [$contents, $mime] = [$this->documents->readContent($documento), $documento->getMimeType()];
            }
            $text = $this->engine->extract($contents, $mime);
            unset($contents);
            // Sullo stato attuale: nel frattempo il documento può essere stato cifrato.
            $this->documents->aggiornaBloccato($documento, fn (Documento $d) => $this->documents->storeOcrText($d, $text));
            $this->logger->info('OCR completato per il documento {id} ({chars} caratteri)', ['id' => $message->documentoId, 'chars' => mb_strlen($text)]);
        } catch (\Throwable $e) {
            $documento->failOcr($e->getMessage());
            $this->em->flush();
            $this->logger->error('OCR fallito per il documento {id}: {reason}', ['id' => $message->documentoId, 'reason' => $e->getMessage(), 'exception' => $e]);
            $this->audit->log('documento.ocr_failed', AuditOutcome::FAILURE, resourceType: 'documento', resourceId: $message->documentoId,
                tenantId: $documento->getTenant()->getId(), context: ['reason' => mb_substr($e->getMessage(), 0, 300)]);

            return;
        }

        // Fuori dal try: un errore di un listener non deve far risultare fallito un OCR riuscito.
        try {
            $this->events->dispatch(new DocumentoOcrCompletatoEvent($documento));
        } catch (\Throwable $e) {
            $this->logger->error('Elaborazione successiva all\'OCR non avviata per il documento {id}: {reason}', ['id' => $message->documentoId, 'reason' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
