<?php

namespace App\Documenti\Classificazione;

use App\Documenti\Entity\Documento;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Documenti\Message\ClassificaDocumentoMessage;
use App\Import\Enum\ImportStrategia;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dopo l'OCR: se il documento è in "Da categorizzare" ed è stato importato da una
 * sorgente con strategia ai_contenuto, mette in coda la classificazione.
 * Job separato dall'OCR: si ritenta da solo e un Ollama lento o spento non
 * tocca lo stato dell'OCR.
 */
final class ClassificazioneAutomaticaListener
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public static function isCandidate(Documento $documento): bool
    {
        return $documento->getCategoria()->isDaCategorizzare()
            && ImportStrategia::AI_CONTENUTO === $documento->getImportSource()?->getStrategia();
    }

    #[AsEventListener]
    public function onOcrCompletato(DocumentoOcrCompletatoEvent $event): void
    {
        if (self::isCandidate($event->documento)) {
            $this->bus->dispatch(new ClassificaDocumentoMessage((string) $event->documento->getId()));
        }
    }
}
