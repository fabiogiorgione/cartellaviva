<?php

namespace App\Pratiche\MessageHandler;

use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Event\DocumentoOcrCompletatoEvent;
use App\Pratiche\CandidatiPratica;
use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaAnalisi;
use App\Pratiche\Entity\PraticaVoce;
use App\Pratiche\Enum\PraticaStato;
use App\Pratiche\Enum\VoceOrigine;
use App\Pratiche\Enum\VoceStato;
use App\Pratiche\EstrattoreVoci;
use App\Pratiche\Message\AnalizzaDocumentoPraticaMessage;
use App\Pratiche\Modello\ModelliPratica;
use App\Pratiche\PraticaCifratura;
use App\Pratiche\Repository\PraticaRepository;
use App\Shared\Doctrine\PerId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Analisi di un documento per una pratica: le voci trovate diventano proposte
 * (mai confermate da sole). Il testo, anche quello dei documenti cifrati
 * (solo con il consenso della pratica), vive in memoria e va solo
 * all'Ollama locale; si salvano solo i valori, cifrati con la chiave della pratica.
 */
#[AsMessageHandler]
final class AnalizzaDocumentoPraticaHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly ModelliPratica $modelli,
        private readonly EstrattoreVoci $estrattore,
        private readonly PraticaCifratura $cifratura,
        private readonly PraticaRepository $pratiche,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Un documento nuovo (o riletto) interessa le pratiche in corso della famiglia. */
    #[AsEventListener]
    public function onOcrCompletato(DocumentoOcrCompletatoEvent $event): void
    {
        $d = $event->documento;
        foreach ($this->pratiche->inCorso($d->getTenant()) as $pratica) {
            if ($pratica->analisiSulTelefono()) {
                continue;
            }
            $modello = $this->modelli->trova($pratica->getModello(), $pratica->getAnno());
            if (null !== $modello && CandidatiPratica::interessa($modello, $pratica, $d)) {
                $this->bus->dispatch(new AnalizzaDocumentoPraticaMessage((string) $pratica->getId(), (string) $d->getId()));
            }
        }
    }

    public function __invoke(AnalizzaDocumentoPraticaMessage $message): void
    {
        $pratica = PerId::trova($this->em, Pratica::class, $message->praticaId);
        $documento = PerId::trova($this->em, Documento::class, $message->documentoId);
        // Le pratiche analizzate sull'iPhone le fa il telefono (messaggi rimasti in coda compresi).
        if (null === $pratica || null === $documento || PraticaStato::IN_CORSO !== $pratica->getStato() || $documento->getTenant() !== $pratica->getTenant() || $pratica->analisiSulTelefono()) {
            return;
        }
        $modello = $this->modelli->trova($pratica->getModello(), $pratica->getAnno());
        if (null === $modello || !CandidatiPratica::interessa($modello, $pratica, $documento)
            || null !== $this->em->find(PraticaAnalisi::class, ['pratica' => $pratica, 'documento' => $documento])) {
            return;
        }

        $persone = $pratica->getPersone();
        $membri = array_values(array_filter($documento->getMembri()->toArray(), static fn ($m) => \in_array($m, $persone, true)));
        try {
            $testo = (string) $this->documents->readOcrText($documento);
            $risultato = $this->estrattore->estrai($modello, $documento->getCategoria()->getSlug(), $documento->getNomeFileOriginale(), $testo, $persone, 1 === \count($membri) ? $membri[0] : null, CandidatiPratica::vecchio($modello, $documento));
            unset($testo);
        } catch (\Throwable $e) {
            $this->logger->warning('Pratica {p}: analisi del documento {d} fallita ({reason})', ['p' => $message->praticaId, 'd' => $message->documentoId, 'reason' => $e->getMessage()]);
            $this->registra($pratica, $documento, PraticaAnalisi::ERRORE, 'errore');

            return;
        }

        foreach ($risultato['voci'] as $v) {
            $voce = new PraticaVoce($pratica, $v['requisito'], $v['persona'], $documento, VoceStato::PROPOSTA, VoceOrigine::AI_SERVER);
            $this->cifratura->cifra($voce, $v['valori']);
            $this->em->persist($voce);
        }
        $this->registra($pratica, $documento, [] === $risultato['voci'] ? PraticaAnalisi::NESSUNA : PraticaAnalisi::VOCI, $risultato['metodo']);
    }

    private function registra(Pratica $pratica, Documento $documento, string $esito, string $metodo): void
    {
        $this->em->persist(new PraticaAnalisi($pratica, $documento, $esito, $metodo));
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Analizzato nel frattempo da un altro worker: le sue voci bastano.
        }
    }
}
