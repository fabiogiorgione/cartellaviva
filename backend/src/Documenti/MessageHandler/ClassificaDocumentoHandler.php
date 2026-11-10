<?php

namespace App\Documenti\MessageHandler;

use App\Documenti\Classificazione\ClassificazioneAutomaticaListener;
use App\Documenti\Classificazione\ClassifierInterface;
use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Categoria;
use App\Documenti\Entity\Documento;
use App\Documenti\Message\ClassificaDocumentoMessage;
use App\Documenti\Repository\CategoriaRepository;
use App\Shared\Doctrine\PerId;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Classificazione automatica di un documento importato (strategia ai_contenuto).
 *
 * - risposta valida (una categoria reale del tenant): il documento viene spostato.
 *   Verso una categoria sensibile resta/diventa cifrato; verso una NON sensibile
 *   resta comunque cifrato (policy: la cifratura non si toglie mai in automatico);
 * - "nessuna" o risposta non valida: il documento resta in "Da categorizzare".
 * Il testo OCR decifrato vive solo in memoria e va solo al servizio Ollama locale.
 * Se Ollama non risponde l'eccezione risale e Messenger ritenta il messaggio.
 */
#[AsMessageHandler]
final class ClassificaDocumentoHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly CategoriaRepository $categorie,
        private readonly ClassifierInterface $classifier,
        private readonly DocumentAudit $audit,
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

        $testo = (string) $this->documents->readOcrText($documento);
        $candidate = array_values(array_filter($this->categorie->findUsableIn($documento->getTenant()), static fn (Categoria $c) => !$c->isDaCategorizzare()));
        if ('' === trim($testo) || [] === $candidate) {
            $this->audit->write($documento, 'documento.classificazione_incerta', ['motivo' => '' === trim($testo) ? 'testo_ocr_vuoto' : 'nessuna_categoria']);

            return;
        }

        $result = $this->classifier->classify($documento->getNomeFileOriginale(), $testo, array_map(static fn (Categoria $c) => $c->getNome(), $candidate));
        unset($testo);

        $scelta = null;
        foreach ($candidate as $categoria) {
            // A parità di nome (sistema e tenant) vince la sensibile.
            if ($categoria->getNome() === $result->categoria && (null === $scelta || $categoria->isSensibile())) {
                $scelta = $categoria;
            }
        }
        if (null === $scelta) {
            $this->audit->write($documento, 'documento.classificazione_incerta', ['modello' => $result->modello, 'risposta' => $result->risposta]);

            return;
        }

        $da = $documento->getCategoria()->getNome();
        $this->documents->changeCategoria($documento, $scelta);
        $this->em->flush();
        $this->audit->write($documento, 'documento.classificato', ['da' => $da, 'a' => $scelta->getNome(), 'modello' => $result->modello, 'automatico' => true]);
    }
}
