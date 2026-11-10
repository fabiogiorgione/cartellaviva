<?php

namespace App\Ente;

use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\ModelloRichiesta;
use App\Ente\Entity\Richiesta;
use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\Enum\DestinatarioStato;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Regole automatiche degli enti, una volta al giorno (src/Schedule.php):
 * - rinnovo: N giorni prima che scada un documento consegnato (es. certificato
 *   medico) parte una nuova richiesta al solo interessato, stesso flusso;
 * - ricorrenze: i modelli con data annuale partono verso tutti gli iscritti
 *   dell'anno in corso (es. liberatoria foto ogni settembre);
 * - conservazione: le copie ricevute si eliminano dopo i mesi indicati.
 */
final class AutomazioniEnte
{
    /** Giorni in cui una richiesta ricorrente non inviata viene ritentata. */
    public const RICORRENZA_RIPROVA_GIORNI = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RichiesteService $richieste,
        private readonly FileCifratoService $files,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{rinnovi: int, ricorrenze: int, fileEliminati: int} */
    public function esegui(?\DateTimeImmutable $oggi = null): array
    {
        $oggi ??= new \DateTimeImmutable('today');

        return ['rinnovi' => $this->rinnovi($oggi), 'ricorrenze' => $this->ricorrenze($oggi), 'fileEliminati' => $this->conservazione($oggi)];
    }

    public function rinnovi(\DateTimeImmutable $oggi): int
    {
        $candidati = $this->em->createQuery('SELECT d, r FROM '.RichiestaDestinatario::class.' d JOIN d.richiesta r
            WHERE d.stato = :c AND d.validoFino IS NOT NULL AND d.rinnovataIl IS NULL AND r.rinnovoGiorni IS NOT NULL AND r.annullataIl IS NULL')
            ->setParameter('c', DestinatarioStato::COMPLETATA)->getResult();
        $n = 0;
        foreach ($candidati as $d) {
            $r = $d->getRichiesta();
            // Ente in eliminazione: sola lettura, niente nuovi invii.
            if ($r->getTenant()->isInEliminazione()) {
                continue;
            }
            if ($d->getValidoFino()->modify(sprintf('-%d days', $r->getRinnovoGiorni())) > $oggi || null !== $d->getRinnovataIl()) {
                continue;
            }
            // Un solo rinnovo per iscritto, anche se hanno risposto due famiglie.
            foreach ($r->getDestinatari() as $altro) {
                if ($altro->getIscritto() === $d->getIscritto()) {
                    $altro->segnaRinnovata();
                }
            }
            try {
                $entro = max($d->getValidoFino(), $oggi->modify('+7 days'));
                $nuova = $this->richieste->invia($r->getTenant(), $r->getCreatoDa(), $r->getTipo(), $r->getTitolo(), $r->getIstruzioni(), $entro, $r->getFirmatari(), $r->getGruppo(), [$d->getIscritto()], $r->getAllegato(),
                    new RegoleRichiesta($r->getModello(), true, $r->getRinnovoGiorni(), $r->getConservazioneMesi(), $d, $r->getModalitaFirma()));
                $this->audit->log('richiesta.rinnovo_automatico', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta', resourceId: (string) $nuova->getId(), tenantId: $r->getTenant()->getId(), context: ['da' => (string) $r->getId()]);
                ++$n;
            } catch (\Throwable $e) {
                // Es. referenti non più raggiungibili: si segnala e non si riprova ogni giorno.
                $this->em->flush();
                $this->logger->warning('Rinnovo automatico non inviato per la richiesta {id}: {motivo}', ['id' => (string) $r->getId(), 'motivo' => $e->getMessage()]);
                $this->audit->log('richiesta.rinnovo_automatico', AuditOutcome::FAILURE, resourceType: 'ente_richiesta', resourceId: (string) $r->getId(), tenantId: $r->getTenant()->getId(), context: ['motivo' => $e->getMessage()]);
            }
        }
        $this->em->flush();

        return $n;
    }

    public function ricorrenze(\DateTimeImmutable $oggi): int
    {
        $modelli = $this->em->createQuery('SELECT m FROM '.ModelloRichiesta::class.' m WHERE m.prossimoInvio IS NOT NULL AND m.prossimoInvio <= :oggi')
            ->setParameter('oggi', $oggi, 'date_immutable')->getResult();
        $n = 0;
        foreach ($modelli as $m) {
            \assert($m instanceof ModelloRichiesta);
            if ($m->getTenant()->isInEliminazione()) {
                continue;
            }
            // Già inviata da questo modello dalla data prevista (invio riuscito ma
            // interrotto prima di avanzare, o mandata a mano dall'ente): niente doppioni.
            $giaInviata = (int) $this->em->createQuery('SELECT COUNT(r.id) FROM '.Richiesta::class.' r WHERE r.modello = :m AND r.createdAt >= :da')
                ->setParameter('m', $m->getId(), 'uuid')->setParameter('da', $m->getProssimoInvio(), 'datetime_immutable')->getSingleScalarResult();
            if ($giaInviata > 0) {
                $m->avanzaRicorrenza($oggi);
                $this->em->flush();
                continue;
            }
            $anno = $this->em->getRepository(AnnoEnte::class)->corrente($m->getTenant());
            $iscritti = null === $anno ? [] : $this->em->createQuery('SELECT DISTINCT i FROM '.Iscritto::class.' i JOIN i.gruppi g WHERE g.anno = :a ORDER BY i.cognome, i.nome')
                ->setParameter('a', $anno->getId(), 'uuid')->getResult();
            try {
                if ([] === $iscritti) {
                    throw new \RuntimeException('nessun iscritto nell\'anno in corso');
                }
                $nuova = $this->richieste->invia($m->getTenant(), $m->getCreatoDa(), $m->getTipo(), $m->getTitolo(), $m->getIstruzioni(), $oggi->modify(sprintf('+%d days', $m->getGiorniRisposta())), $m->getFirmatari(), null, $iscritti, $m->getAllegato(), RegoleRichiesta::daModello($m));
                $m->avanzaRicorrenza($oggi);
                $this->em->flush();
                $this->audit->log('richiesta.ricorrente', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta', resourceId: (string) $nuova->getId(), tenantId: $m->getTenant()->getId(), context: ['modello' => (string) $m->getId()]);
                ++$n;
            } catch (\Throwable $e) {
                // La data resta: il job riprova ogni giorno (es. iscritti caricati
                // pochi giorni dopo il passaggio d'anno), poi rinuncia per quest'anno.
                $rinuncia = $m->getProssimoInvio() <= $oggi->modify(sprintf('-%d days', self::RICORRENZA_RIPROVA_GIORNI));
                if ($rinuncia) {
                    $m->avanzaRicorrenza($oggi);
                    $this->em->flush();
                }
                $this->logger->warning('Richiesta ricorrente del modello {id} non inviata: {motivo}', ['id' => (string) $m->getId(), 'motivo' => $e->getMessage()]);
                $this->audit->log('richiesta.ricorrente', AuditOutcome::FAILURE, resourceType: 'ente_modello', resourceId: (string) $m->getId(), tenantId: $m->getTenant()->getId(), context: ['motivo' => $e->getMessage(), 'rinuncia' => $rinuncia]);
            }
        }

        return $n;
    }

    public function conservazione(\DateTimeImmutable $oggi): int
    {
        $candidati = $this->em->createQuery('SELECT d, r FROM '.RichiestaDestinatario::class.' d JOIN d.richiesta r WHERE d.risposta IS NOT NULL AND r.conservazioneMesi IS NOT NULL')->getResult();
        $n = 0;
        foreach ($candidati as $d) {
            $conclusa = $d->getVerificataIl() ?? $d->getRispostaIl();
            if (!$d->getStato()->isConclusa() || null === $conclusa || $conclusa->modify(sprintf('+%d months', $d->getRichiesta()->getConservazioneMesi())) > $oggi) {
                continue;
            }
            $file = $d->getRisposta();
            // Il file può servire ad altre consegne? No: ogni consegna ha il suo.
            $d->rimuoviFile();
            $this->files->delete($file);
            $this->em->remove($file);
            $this->audit->log('richiesta.copia_eliminata', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta_destinatario', resourceId: (string) $d->getId(), tenantId: $d->getRichiesta()->getTenant()->getId(), context: ['motivo' => 'conservazione']);
            ++$n;
        }
        $this->em->flush();

        return $n;
    }
}
