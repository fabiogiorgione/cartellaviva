<?php

namespace App\Documenti\MessageHandler;

use App\ConnettoreAi\LavoriClassificazione;
use App\Documenti\Classificazione\ClassificazioneAutomatica;
use App\Documenti\Classificazione\ClassificazioneAutomaticaListener;
use App\Documenti\Classificazione\ClassifierInterface;
use App\Documenti\Entity\Documento;
use App\Documenti\Message\ClassificaDocumentoMessage;
use App\Shared\Doctrine\PerId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Classificazione automatica di un documento importato (strategia ai_contenuto):
 * le regole sono in ClassificazioneAutomatica. Con il Mac della famiglia online
 * la domanda al modello la fa il Mac (LavoriClassificazione) e qui non si
 * chiama Ollama; se il Mac non risponde torna qui con forzaServer.
 * Se Ollama non risponde l'eccezione risale e Messenger ritenta il messaggio.
 */
#[AsMessageHandler]
final class ClassificaDocumentoHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClassifierInterface $classifier,
        private readonly ClassificazioneAutomatica $classificazione,
        private readonly LavoriClassificazione $sulMac,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ClassificaDocumentoMessage $message): void
    {
        $documento = PerId::trova($this->em, Documento::class, $message->documentoId);
        // Ricontrollo: nel frattempo il documento può essere stato cancellato o spostato a mano.
        if (null === $documento || !ClassificazioneAutomaticaListener::isCandidate($documento)) {
            return;
        }
        if (!$this->classifier->isEnabled()) {
            $this->logger->info('Classificazione automatica disattivata (OLLAMA_MODEL/OLLAMA_URL vuoti): documento {id} resta da categorizzare', ['id' => $message->documentoId]);

            return;
        }
        if (!$message->forzaServer && $this->sulMac->instradaImport($documento)) {
            return;
        }

        $this->classificazione->classifica($documento, $this->classifier);
    }
}
