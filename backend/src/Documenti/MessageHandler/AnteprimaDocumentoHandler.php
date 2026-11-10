<?php

namespace App\Documenti\MessageHandler;

use App\Documenti\Anteprima\GeneratoreAnteprima;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Enum\AnteprimaStato;
use App\Documenti\Enum\OcrStatus;
use App\Documenti\Message\AnteprimaDocumentoMessage;
use App\Documenti\Message\OcrDocumentoMessage;
use App\Documenti\UploadPolicy;
use App\Shared\Audit\AuditLogger;
use App\Shared\Doctrine\PerId;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Worker: copia per l'anteprima. Il file si decifra solo in memoria, la copia
 * si salva cifrata come l'originale. Per i documenti Office l'OCR parte dopo,
 * sul PDF ricavato (Tesseract non legge i DOCX): se l'anteprima fallisce,
 * fallisce anche il testo, con il motivo.
 */
#[AsMessageHandler]
final class AnteprimaDocumentoHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly GeneratoreAnteprima $generatore,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(AnteprimaDocumentoMessage $message): void
    {
        $documento = PerId::trova($this->em, Documento::class, $message->documentoId);
        if (!$documento instanceof Documento || AnteprimaStato::IN_CODA !== $documento->getAnteprimaStato()) {
            return; // cancellato, o già elaborato
        }
        $ufficio = UploadPolicy::isUfficio($documento->getMimeType());

        try {
            [$copia, $mime] = $this->generatore->genera($this->documents->readContent($documento), $documento->getMimeType(), $documento->getNomeFileOriginale());
            // Sullo stato attuale: nel frattempo il documento può essere stato cifrato.
            $this->documents->aggiornaBloccato($documento, fn (Documento $d) => $this->documents->storeAnteprima($d, $copia, $mime));
            unset($copia);
        } catch (\Throwable $e) {
            $documento->failAnteprima($e->getMessage());
            if ($ufficio && OcrStatus::IN_CODA === $documento->getOcrStatus()) {
                $documento->failOcr('Testo non disponibile: '.$e->getMessage());
            }
            $this->em->flush();
            $this->logger->error('Anteprima non generata per il documento {id}: {reason}', ['id' => $message->documentoId, 'reason' => $e->getMessage()]);
            $this->audit->log('documento.anteprima_fallita', AuditOutcome::FAILURE, resourceType: 'documento', resourceId: $message->documentoId,
                tenantId: $documento->getTenant()->getId(), context: ['reason' => mb_substr($e->getMessage(), 0, 300)]);

            return;
        }

        if ($ufficio && OcrStatus::IN_CODA === $documento->getOcrStatus()) {
            $this->bus->dispatch(new OcrDocumentoMessage($message->documentoId));
        }
    }
}
