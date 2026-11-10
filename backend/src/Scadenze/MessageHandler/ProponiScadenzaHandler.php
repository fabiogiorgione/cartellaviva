<?php

namespace App\Scadenze\MessageHandler;

use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Scadenze\AiScadenzaSuggeritore;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Message\ProponiScadenzaMessage;
use App\Scadenze\ScadenzaEstrattore;
use App\Shared\Doctrine\PerId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Scadenze proposte dai documenti (fase 7.1).
 *
 * Dopo l'OCR si cercano nel testo le date future vicine a "scadenza", "valido
 * fino"…; con Ollama attivo il modello sceglie tra QUELLE date (mai una data
 * inventata) e suggerisce il tipo di documento. Se Ollama non risponde vale la
 * scelta deterministica. Il risultato è solo una proposta sul documento:
 * nessuna scadenza nasce senza la conferma dell'utente.
 * Il testo decifrato vive solo in memoria e va solo all'Ollama locale.
 */
#[AsMessageHandler]
final class ProponiScadenzaHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly ScadenzaEstrattore $estrattore,
        private readonly AiScadenzaSuggeritore $ai,
        private readonly DocumentAudit $audit,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener]
    public function onOcrCompletato(DocumentoOcrCompletatoEvent $event): void
    {
        if (self::isCandidate($this->em, $event->documento)) {
            $this->bus->dispatch(new ProponiScadenzaMessage((string) $event->documento->getId()));
        }
    }

    /** Una sola proposta per documento, e non se ha già una scadenza collegata. */
    public static function isCandidate(EntityManagerInterface $em, Documento $documento): bool
    {
        return null === $documento->getScadenzaPropostaStato()
            && null === $em->getRepository(Scadenza::class)->findOneBy(['documento' => $documento]);
    }

    public function __invoke(ProponiScadenzaMessage $message): void
    {
        $documento = PerId::trova($this->em, Documento::class, $message->documentoId);
        if (null === $documento || !self::isCandidate($this->em, $documento)) {
            return;
        }
        $testo = (string) $this->documents->readOcrText($documento);
        $candidati = '' === trim($testo) ? [] : $this->estrattore->candidati($testo);
        if ([] === $candidati) {
            return;
        }

        $data = null;
        $tipo = null;
        $modo = 'regole';
        if ($this->ai->isEnabled()) {
            try {
                // Le più probabili prima; al modello bastano poche date.
                $scelta = $this->ai->scegli($documento->getNomeFileOriginale(), $testo, array_column(\array_slice($candidati, 0, 12), 'data'));
                [$data, $tipo, $modo] = [$scelta['data'], $scelta['tipo'], 'ai'];
            } catch (\Throwable $e) {
                $this->logger->warning('Scadenza proposta: Ollama non disponibile per il documento {id}, uso le regole ({reason})', ['id' => $message->documentoId, 'reason' => $e->getMessage()]);
                $data = $this->estrattore->migliore($testo);
            }
        } else {
            $data = $this->estrattore->migliore($testo);
        }
        unset($testo);
        if (null === $data) {
            return;
        }

        $proposta = ['data' => $data->format('Y-m-d'), 'tipo' => $tipo];
        $this->documents->aggiornaBloccato($documento, fn (Documento $d) => $this->documents->storeScadenzaProposta($d, $proposta));
        // Nell'audit solo il fatto e il metodo: la data è un dato del contenuto.
        $this->audit->write($documento, 'documento.scadenza_proposta', ['metodo' => $modo]);
    }
}
