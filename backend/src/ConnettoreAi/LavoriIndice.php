<?php

namespace App\ConnettoreAi;

use App\ConnettoreAi\Entity\ConnettoreAi;
use App\ConnettoreAi\Entity\LavoroAi;
use App\ConnettoreAi\Repository\ConnettoreAiRepository;
use App\ConnettoreAi\Repository\LavoroAiRepository;
use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Enum\OcrStatus;
use App\Documenti\Indice\AiEstrattoreDati;
use App\Documenti\Indice\DatiEstratti;
use App\Documenti\Indice\IndiceDocumenti;
use Doctrine\ORM\EntityManagerInterface;

/**
 * I dati dell'indice di un documento (emittente, data, importo, numero,
 * intestatario, riferimenti, tag) letti sul Mac della famiglia invece che dalla
 * VM (docs/connettore-ai.md). Le istruzioni, il prompt, lo schema e i controlli
 * sul testo sono quelli di AiEstrattoreDati: il server ricontrolla la risposta
 * del Mac come quella del suo Ollama. I vettori per la ricerca restano al server.
 * Solo documenti in chiaro: quelli cifrati non hanno mai un indice.
 */
class LavoriIndice
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LavoroAiRepository $lavori,
        private readonly ConnettoreAiRepository $connettori,
        private readonly AiEstrattoreDati $estrattore,
        private readonly IndiceDocumenti $indice,
        private readonly DocumentService $documents,
        private readonly DocumentAudit $audit,
    ) {
    }

    /**
     * I dati del documento vanno al Mac? Sì se il documento è in chiaro e un
     * connettore della famiglia è online. Crea il lavoro una volta sola.
     */
    public function instrada(Documento $d): bool
    {
        if ($d->isCifrato() || null === $this->connettori->online($d->getTenant(), new \DateTimeImmutable())) {
            return false;
        }
        if (null === $this->lavori->perDocumentoIndice($d)) {
            $this->em->persist(new LavoroAi(null, $d, LavoroAi::INDICE_DATI));
            $this->em->flush();
        }

        return true;
    }

    /**
     * La richiesta per il Mac, o null se il lavoro non è più da fare (documento
     * cifrato nel frattempo, testo non più disponibile o vuoto).
     *
     * @return array{istruzioni: string, prompt: string, schema: string}|null
     */
    public function richiesta(LavoroAi $l): ?array
    {
        $testo = $this->testo($l->getDocumento());

        return null === $testo ? null : $this->estrattore->richiesta($l->getDocumento()->getNomeFileOriginale(), $testo);
    }

    /**
     * La risposta del modello sul Mac, ricontrollata sul testo e salvata.
     *
     * @return int|null quanti dati validi (un tag conta uno); null se il documento non è più da indicizzare
     */
    public function risultato(LavoroAi $l, ConnettoreAi $c, string $risposta, string $modello): ?int
    {
        $d = $l->getDocumento();
        $testo = $this->testo($d);
        if (null === $testo) {
            return null;
        }
        $dati = $this->estrattore->interpreta($risposta, $testo);
        unset($testo);
        if (!$this->salvaDati($d, $dati, $modello)) {
            return null;
        }
        // Nell'audit solo il fatto, il modello e il Mac: i dati estratti sono contenuto del documento.
        $this->audit->write($d, 'documento.indicizzato', ['modello_dati' => $modello, 'connettore_ai' => (string) $c->getId()]);

        return \count(array_filter($dati->dati(), static fn ($v) => null !== $v && '' !== $v)) + \count($dati->tag) + \count($dati->riferimenti);
    }

    /**
     * Salva solo i dati (i vettori restano) e la data del documento se manca.
     * Con il documento bloccato: se nel frattempo è diventato cifrato non si salva nulla.
     *
     * @return bool false se il documento è cifrato
     */
    public function salvaDati(Documento $documento, DatiEstratti $dati, ?string $modello): bool
    {
        $salvato = false;
        $this->documents->aggiornaBloccato($documento, function (Documento $d) use ($dati, $modello, &$salvato): void {
            if ($d->isCifrato()) {
                return;
            }
            $this->indice->salvaDati($d, $dati, $modello);
            // Solo se manca: una data inserita a mano non si sovrascrive mai.
            if (null === $d->getDataDocumento()) {
                $d->setDataDocumento($dati->dataDocumento());
            }
            $salvato = true;
        });

        return $salvato;
    }

    /** Il testo da analizzare, o null se il documento non è più da indicizzare. */
    private function testo(Documento $d): ?string
    {
        if ($d->isCifrato() || OcrStatus::COMPLETATO !== $d->getOcrStatus()) {
            return null;
        }
        $testo = trim((string) $d->getOcrText());

        return '' === $testo ? null : $testo;
    }
}
