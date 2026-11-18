<?php

namespace App\Documenti\Classificazione;

use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Categoria;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\CategoriaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Classificazione automatica di un documento importato (strategia ai_contenuto),
 * con il classificatore indicato: l'Ollama del server (ClassificaDocumentoHandler)
 * o il Mac della famiglia (ConnettoreAi\LavoriClassificazione).
 *
 * - risposta valida (una categoria reale del tenant): il documento viene spostato.
 *   Verso una categoria sensibile resta/diventa cifrato; verso una NON sensibile
 *   resta comunque cifrato (policy: la cifratura non si toglie mai in automatico);
 * - "nessuna" o risposta non valida: il documento resta in "Da categorizzare".
 * Il testo OCR decifrato vive solo in memoria e va solo al modello.
 * Prima di ogni modifica il classificatore risponde: se lancia un'eccezione
 * (Ollama spento, risposta del Mac in attesa) il documento non cambia.
 */
class ClassificazioneAutomatica
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
        private readonly CategoriaRepository $categorie,
        private readonly DocumentAudit $audit,
    ) {
    }

    public function classifica(Documento $documento, ClassifierInterface $classifier): void
    {
        $testo = (string) $this->documents->readOcrText($documento);
        $candidate = array_values(array_filter($this->categorie->findUsableIn($documento->getTenant()), static fn (Categoria $c) => !$c->isDaCategorizzare()));
        if ('' === trim($testo) || [] === $candidate) {
            $this->audit->write($documento, 'documento.classificazione_incerta', ['motivo' => '' === trim($testo) ? 'testo_ocr_vuoto' : 'nessuna_categoria']);

            return;
        }

        $result = $classifier->classify($documento->getNomeFileOriginale(), $testo, array_map(static fn (Categoria $c) => $c->getNome(), $candidate));
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
