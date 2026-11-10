<?php

namespace App\Scadenze;

use App\Account\Entity\User;
use App\Documenti\Entity\Documento;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Enum\ScadenzaOrigine;
use App\Scadenze\Enum\ScadenzaStato;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use Doctrine\ORM\EntityManagerInterface;

/** Azioni sulle scadenze oltre al CRUD: segnarle fatte, ignorare quelle proposte. */
final class ScadenzeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Segna fatta una scadenza aperta. Se si ripete, restituisce la successiva
     * (già salvata). Le scadenze delle richieste degli enti si chiudono rispondendo.
     */
    public function segnaFatta(Scadenza $scadenza, User $chi): ?Scadenza
    {
        if (ScadenzaOrigine::RICHIESTA === $scadenza->getOrigine()) {
            throw new Conflitto('Questa scadenza segue una richiesta di un ente: si chiude quando rispondi alla richiesta.');
        }
        if (ScadenzaStato::APERTA !== $scadenza->getStato()) {
            throw new Conflitto('La scadenza è già stata segnata come fatta.');
        }
        $prossima = $scadenza->complete($chi);
        if (null !== $prossima) {
            $this->em->persist($prossima);
        }
        $this->em->flush();
        $this->audit->log('scadenza.completed', AuditOutcome::SUCCESS, resourceType: 'scadenza', resourceId: (string) $scadenza->getId(),
            tenantId: $scadenza->getTenant()->getId(), context: ['prossima' => $prossima?->getData()->format('Y-m-d')]);

        return $prossima;
    }

    /** La scadenza proposta dal testo del documento non interessa. */
    public function ignoraProposta(Documento $documento): void
    {
        $documento->chiudiScadenzaProposta(Documento::PROPOSTA_IGNORATA);
        $this->em->flush();
        $this->audit->log('documento.scadenza_proposta_ignorata', AuditOutcome::SUCCESS, resourceType: 'documento', resourceId: (string) $documento->getId(), tenantId: $documento->getTenant()->getId());
    }
}
