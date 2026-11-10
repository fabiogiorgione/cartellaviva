<?php

namespace App\Documenti\MessageHandler;

use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Enum\OcrStatus;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Documenti\Indice\AiEstrattoreDati;
use App\Documenti\Indice\DatiEstratti;
use App\Documenti\Indice\EmbedderInterface;
use App\Documenti\Indice\IndiceDocumenti;
use App\Documenti\Indice\ModelloNonDisponibileException;
use App\Documenti\Indice\SuddivisoreTesto;
use App\Documenti\Message\IndicizzaDocumentoMessage;
use App\Shared\Doctrine\PerId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Indice dei documenti NON cifrati, dopo ogni OCR riuscito:
 * - dati estratti (emittente, data, importo, numero, intestatario), riferimenti e tag (AiEstrattoreDati);
 * - vettori delle parti del testo per la ricerca per significato e i documenti simili;
 * - la data del documento, se non è già indicata.
 *
 * I documenti cifrati non si indicizzano mai: i vettori e i dati estratti
 * rivelerebbero il contenuto. Il controllo si rifà sullo stato attuale, con il
 * documento bloccato, prima di salvare: un documento cifrato nel frattempo
 * non riceve l'indice. Se Ollama non risponde l'eccezione risale e Messenger
 * ritenta il messaggio; se un modello non è scaricato quella parte si salta
 * (nessun nuovo tentativo: app:documenti:indicizza la recupera dopo il pull).
 */
#[AsMessageHandler]
final class IndicizzaDocumentoHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly IndiceDocumenti $indice,
        private readonly AiEstrattoreDati $estrattore,
        private readonly EmbedderInterface $embedder,
        private readonly DocumentAudit $audit,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener]
    public function onOcrCompletato(DocumentoOcrCompletatoEvent $event): void
    {
        if (!$event->documento->isCifrato()) {
            $this->bus->dispatch(new IndicizzaDocumentoMessage((string) $event->documento->getId()));
        }
    }

    public function __invoke(IndicizzaDocumentoMessage $message): void
    {
        $documento = PerId::trova($this->em, Documento::class, $message->documentoId);
        if (null === $documento || $documento->isCifrato() || OcrStatus::COMPLETATO !== $documento->getOcrStatus()) {
            return;
        }
        if (!$this->estrattore->isEnabled() && !$this->embedder->isEnabled()) {
            $this->logger->info('Indice dei documenti disattivato (OLLAMA_MODEL e OLLAMA_EMBED_MODEL vuoti): documento {id} non indicizzato', ['id' => $message->documentoId]);

            return;
        }

        $testo = trim((string) $documento->getOcrText());
        $nomeFile = $documento->getNomeFileOriginale();
        $dati = new DatiEstratti();
        $modelloDati = null;
        $vettori = [];
        [$tentati, $mancanti] = [0, 0];
        if ('' !== $testo && $this->estrattore->isEnabled()) {
            ++$tentati;
            try {
                $dati = $this->estrattore->estrai($nomeFile, $testo);
                $modelloDati = $this->estrattore->modello();
            } catch (ModelloNonDisponibileException $e) {
                ++$mancanti;
                $this->logger->warning('Indice del documento {id}: dati non estratti ({reason})', ['id' => $message->documentoId, 'reason' => $e->getMessage()]);
            }
        }
        if ('' !== $testo && $this->embedder->isEnabled()) {
            ++$tentati;
            try {
                // Il nome del file dà contesto a ogni parte ("bolletta_enel_marzo.pdf").
                $vettori = $this->embedder->embed(array_map(static fn (string $p) => "Documento: {$nomeFile}\n{$p}", SuddivisoreTesto::parti($testo)));
            } catch (ModelloNonDisponibileException $e) {
                ++$mancanti;
                $this->logger->warning('Indice del documento {id}: ricerca per significato non disponibile ({reason})', ['id' => $message->documentoId, 'reason' => $e->getMessage()]);
            }
        }
        unset($testo);
        if ($tentati > 0 && $mancanti === $tentati) {
            return; // nessun modello utile scaricato: niente da salvare, si riprova dopo il pull
        }

        $salvato = false;
        $this->documents->aggiornaBloccato($documento, function (Documento $d) use ($dati, $modelloDati, $vettori, &$salvato): void {
            // Cifrato nel frattempo (es. spostato in una categoria sensibile): niente indice.
            if ($d->isCifrato()) {
                return;
            }
            $this->indice->salva($d, $dati, $modelloDati, $this->embedder->modello(), $vettori);
            // Solo se manca: una data inserita a mano non si sovrascrive mai.
            if (null === $d->getDataDocumento()) {
                $d->setDataDocumento($dati->dataDocumento());
            }
            $salvato = true;
        });
        if ($salvato) {
            // Nell'audit solo il fatto e i modelli: i dati estratti sono contenuto del documento.
            $this->audit->write($documento, 'documento.indicizzato', [
                'modello_dati' => $modelloDati,
                'modello_embedding' => [] === $vettori ? null : $this->embedder->modello(),
                'parti' => \count($vettori),
            ]);
        }
    }
}
