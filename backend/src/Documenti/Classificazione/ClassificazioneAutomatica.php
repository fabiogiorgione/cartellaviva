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
 * - risposta valida (una categoria reale del tenant) confermata dalla verifica sì/no
 *   contro la descrizione della categoria (DescrizioneCategoria): il documento viene spostato.
 *   Verso una categoria sensibile resta/diventa cifrato; verso una NON sensibile
 *   resta comunque cifrato (policy: la cifratura non si toglie mai in automatico);
 * - "nessuna", risposta non valida o scartata dalla verifica: il documento resta in
 *   "Da categorizzare". Senza la verifica il modello piccolo sceglie quasi sempre
 *   qualcosa: su 87 casi inventati (misura del 21/11/2026, qwen3 4B) i documenti
 *   spostati nella categoria sbagliata scendono da 27 a 3 su 87.
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
        private readonly DescrizioneCategoria $descrizioni,
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

        $scelta = null;
        foreach ($candidate as $categoria) {
            // A parità di nome (sistema e tenant) vince la sensibile.
            if ($categoria->getNome() === $result->categoria && (null === $scelta || $categoria->isSensibile())) {
                $scelta = $categoria;
            }
        }
        // Senza titolo né altri indizi, come la prima domanda: sono le condizioni misurate.
        $scartata = null !== $scelta && !$classifier->verifica($documento->getNomeFileOriginale(), $testo, $scelta->getNome(),
            $this->descrizioni->perCategoria($classifier, $result->modello, $scelta, $documento), []);
        unset($testo);
        if (null === $scelta || $scartata) {
            $this->audit->write($documento, 'documento.classificazione_incerta', ['modello' => $result->modello, 'risposta' => $result->risposta] + ($scartata ? ['motivo' => 'verifica'] : []));

            return;
        }

        $da = $documento->getCategoria()->getNome();
        $this->documents->changeCategoria($documento, $scelta);
        $this->em->flush();
        $this->audit->write($documento, 'documento.classificato', ['da' => $da, 'a' => $scelta->getNome(), 'modello' => $result->modello, 'automatico' => true]);
    }
}
