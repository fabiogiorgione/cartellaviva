<?php

namespace App\Ente;

use App\Account\Entity\User;
use App\Ente\Entity\ModelloRichiesta;
use App\Ente\Entity\Richiesta;
use App\Ente\Enum\RichiestaTipo;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/** Modelli di richiesta dell'ente: creazione, modifica, eliminazione. */
final class ModelliService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileCifratoService $files,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Crea ($modello null) o aggiorna un modello. Un modello di firma ha bisogno
     * del PDF: nuovo ($pdf) o già allegato.
     *
     * @param array{contenuto: string, nome: string}|null $pdf
     */
    public function salva(Tenant $ente, ?ModelloRichiesta $modello, DatiModello $d, ?array $pdf, User $autore): ModelloRichiesta
    {
        if (!\in_array($d->firmatari, [Richiesta::FIRMATARI_TUTTI, Richiesta::FIRMATARI_UNO], true)) {
            throw new DatiNonValidi('firmatari: tutti o uno.');
        }
        if ((null === $d->ricorrenzaGiorno) !== (null === $d->ricorrenzaMese)) {
            throw new DatiNonValidi('ricorrenza: indica giorno e mese.');
        }
        $nuovo = null === $modello;
        $modello ??= new ModelloRichiesta($ente, $d->nome, $d->tipo, $d->titolo, $autore);
        $modello->aggiorna($d->nome, $d->tipo, $d->titolo, $d->istruzioni, $d->firmatari, $d->chiediValidita, $d->rinnovoGiorni, $d->conservazioneMesi, $d->giorniRisposta);
        $modello->impostaRicorrenza($d->ricorrenzaGiorno, $d->ricorrenzaMese);
        $modello->setModalitaFirma($d->modalitaFirma);
        if (null !== $pdf) {
            $modello->setAllegato($this->files->store($ente, $pdf['contenuto'], $pdf['nome'], true));
        }
        if (RichiestaTipo::FIRMA === $d->tipo && null === $modello->getAllegato()) {
            throw new DatiNonValidi('file: un modello di firma ha bisogno del PDF da firmare.');
        }
        $this->em->persist($modello);
        $this->em->flush();
        $this->audit->log($nuovo ? 'modello.creato' : 'modello.aggiornato', AuditOutcome::SUCCESS, resourceType: 'ente_modello', resourceId: (string) $modello->getId(), tenantId: $ente->getId());

        return $modello;
    }

    public function elimina(ModelloRichiesta $modello): void
    {
        $this->em->remove($modello);
        $this->em->flush();
    }
}
