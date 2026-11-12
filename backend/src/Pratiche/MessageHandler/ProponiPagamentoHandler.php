<?php

namespace App\Pratiche\MessageHandler;

use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Pratiche\AiPagamentoSuggeritore;
use App\Pratiche\AvvisoPagamentoEstrattore;
use App\Pratiche\Entity\Pagamento;
use App\Pratiche\Message\ProponiPagamentoMessage;
use App\Shared\Doctrine\PerId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pagamenti proposti dai documenti (Pratiche, fase 1).
 *
 * Dopo l'OCR si cerca nel testo un avviso di pagamento con regole
 * deterministiche (AvvisoPagamentoEstrattore); l'AI, se attiva, propone solo
 * titolo ed ente. Il risultato è una proposta sul documento: nessun pagamento
 * nasce senza la conferma dell'utente. Il testo decifrato vive solo in memoria
 * e va solo all'Ollama locale.
 */
#[AsMessageHandler]
final class ProponiPagamentoHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly AvvisoPagamentoEstrattore $estrattore,
        private readonly AiPagamentoSuggeritore $ai,
        private readonly DocumentAudit $audit,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener]
    public function onOcrCompletato(DocumentoOcrCompletatoEvent $event): void
    {
        if (self::isCandidate($this->em, $event->documento, false)) {
            $this->bus->dispatch(new ProponiPagamentoMessage((string) $event->documento->getId()));
        }
    }

    /** Una sola proposta per documento, e non se ha già un pagamento collegato (come origine). */
    public static function isCandidate(EntityManagerInterface $em, Documento $documento, bool $rivaluta): bool
    {
        if (!$rivaluta && null !== $documento->getPagamentoPropostaStato()) {
            return false;
        }
        if ($rivaluta && Documento::PROPOSTA_ACCETTATA === $documento->getPagamentoPropostaStato()) {
            return false;
        }

        return null === $em->getRepository(Pagamento::class)->findOneBy(['documento' => $documento]);
    }

    public function __invoke(ProponiPagamentoMessage $message): void
    {
        $documento = PerId::trova($this->em, Documento::class, $message->documentoId);
        if (null === $documento || !self::isCandidate($this->em, $documento, $message->rivaluta)) {
            return;
        }
        $testo = (string) $this->documents->readOcrText($documento);
        $avviso = '' === trim($testo) ? null : $this->estrattore->estrai($testo);
        if (null === $avviso) {
            return;
        }

        $titolo = null;
        $ente = $avviso['ente'];
        $modo = 'regole';
        if ($this->ai->isEnabled()) {
            try {
                $s = $this->ai->suggerisci($documento->getNomeFileOriginale(), $testo);
                $titolo = $s['titolo'];
                $ente = $s['ente'] ?? $ente;
                $modo = 'ai';
            } catch (\Throwable $e) {
                $this->logger->warning('Pagamento proposto: Ollama non disponibile per il documento {id}, uso le regole ({reason})', ['id' => $message->documentoId, 'reason' => $e->getMessage()]);
            }
        }
        unset($testo);
        $titolo ??= null !== $ente ? mb_substr($ente.' avviso di pagamento', 0, 200) : mb_substr($documento->getNomeFileOriginale(), 0, 200);

        $proposta = [
            'titolo' => $titolo,
            'ente' => $ente,
            'importo' => null !== $avviso['importoCentesimi'] ? Pagamento::formattaImporto($avviso['importoCentesimi']) : null,
            'scadenza' => $avviso['scadenza']?->format('Y-m-d'),
            'codiceAvviso' => $avviso['codiceAvviso'],
            'codiceFiscaleEnte' => $avviso['codiceFiscaleEnte'],
        ];
        $this->documents->aggiornaBloccato($documento, fn (Documento $d) => $this->documents->storePagamentoProposta($d, $proposta));
        // Nell'audit solo il fatto e il metodo: importi e codici sono dati del contenuto.
        $this->audit->write($documento, 'documento.pagamento_proposto', ['metodo' => $modo]);
    }
}
