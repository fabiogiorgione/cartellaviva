<?php

namespace App\Ente;

use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Sede;
use App\Ente\Repository\AnnoEnteRepository;
use App\Ente\Repository\IscrittoRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Struttura dell'ente: anni, sedi, gruppi (classi e attività) e operatori dei
 * gruppi. I gruppi di un anno archiviato sono in sola lettura.
 */
final class StrutturaEnteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnnoEnteRepository $anni,
        private readonly IscrittoRepository $iscritti,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Passaggio all'anno successivo. $nuoviNomi: id del gruppo => nome nel nuovo
     * anno (es. "4ªB"); il gruppo passa con iscritti e operatori. I gruppi non
     * indicati restano solo nell'anno archiviato (es. le quinte).
     *
     * @param array<string, string|null> $nuoviNomi
     */
    public function passaggioAnno(Tenant $ente, AnnoEnte $anno, string $nome, array $nuoviNomi): AnnoEnte
    {
        if ($anno->isArchiviato()) {
            throw new Conflitto('Questo anno è già archiviato.');
        }
        if (null !== $this->anni->findOneBy(['tenant' => $ente, 'nome' => $nome])) {
            throw new Conflitto('Esiste già un anno con questo nome.');
        }

        $nuovo = new AnnoEnte($ente, $nome);
        $this->em->persist($nuovo);
        $passati = 0;
        foreach ($this->em->getRepository(Gruppo::class)->findBy(['anno' => $anno]) as $gruppo) {
            $nuovoNome = $nuoviNomi[(string) $gruppo->getId()] ?? null;
            if (null === $nuovoNome) {
                continue;
            }
            $successivo = new Gruppo($ente, $nuovo, $nuovoNome, $gruppo->getTipo(), $gruppo->getSede());
            $successivo->replaceOperatori($gruppo->getOperatori());
            $this->em->persist($successivo);
            foreach ($this->iscritti->delGruppo($gruppo) as $iscritto) {
                $iscritto->iscriviA($successivo);
            }
            ++$passati;
        }
        $anno->archivia();
        $this->em->flush();
        $this->audit->log('ente.passaggio_anno', AuditOutcome::SUCCESS, resourceType: 'ente_anno', resourceId: (string) $nuovo->getId(), tenantId: $ente->getId(), context: ['da' => $anno->getNome(), 'a' => $nome, 'gruppi' => $passati]);

        return $nuovo;
    }

    public function creaSede(Tenant $ente, string $nome, ?string $indirizzo): Sede
    {
        $sede = new Sede($ente, $nome, $indirizzo);
        $this->em->persist($sede);
        $this->em->flush();

        return $sede;
    }

    public function aggiornaSede(Sede $sede, string $nome, ?string $indirizzo): void
    {
        $sede->aggiorna($nome, $indirizzo);
        $this->em->flush();
    }

    public function eliminaSede(Sede $sede): void
    {
        $this->em->remove($sede);
        $this->em->flush();
    }

    /** Nuovo gruppo nell'anno in corso. */
    public function creaGruppo(Tenant $ente, string $nome, string $tipo, ?Sede $sede): Gruppo
    {
        $anno = $this->anni->corrente($ente) ?? throw new Conflitto('Nessun anno in corso.');
        $gruppo = new Gruppo($ente, $anno, $nome, self::tipo($tipo), $sede);
        $this->em->persist($gruppo);
        $this->em->flush();

        return $gruppo;
    }

    public function aggiornaGruppo(Gruppo $gruppo, string $nome, string $tipo, ?Sede $sede): void
    {
        self::modificabile($gruppo);
        $gruppo->aggiorna($nome, self::tipo($tipo), $sede);
        $this->em->flush();
    }

    public function eliminaGruppo(Gruppo $gruppo): void
    {
        self::modificabile($gruppo);
        if ([] !== $this->iscritti->delGruppo($gruppo)) {
            throw new Conflitto('Il gruppo ha ancora degli iscritti: spostali o toglili prima di eliminarlo.');
        }
        $this->em->remove($gruppo);
        $this->em->flush();
    }

    /**
     * Operatori del gruppo: solo membri attivi dell'ente.
     *
     * @param list<Membership|null> $operatori null = id che non corrisponde a nessun membro
     */
    public function assegnaOperatori(Tenant $ente, Gruppo $gruppo, array $operatori): void
    {
        self::modificabile($gruppo);
        foreach ($operatori as $m) {
            if (null === $m || !$m->getTenant()->getId()->equals($ente->getId()) || MembershipStatus::ACTIVE !== $m->getStatus()) {
                throw new DatiNonValidi('membershipIds: solo membri attivi dell\'ente.');
            }
        }
        /** @var list<Membership> $operatori */
        $gruppo->replaceOperatori($operatori);
        $this->em->flush();
        $this->audit->log('ente.gruppo_operatori', AuditOutcome::SUCCESS, resourceType: 'ente_gruppo', resourceId: (string) $gruppo->getId(), tenantId: $ente->getId(), context: ['operatori' => array_map(static fn (Membership $m) => (string) $m->getId(), $operatori)]);
    }

    private static function modificabile(Gruppo $gruppo): void
    {
        if ($gruppo->getAnno()->isArchiviato()) {
            throw new Conflitto('Il gruppo appartiene a un anno archiviato: è in sola lettura.');
        }
    }

    private static function tipo(string $tipo): string
    {
        if (!\in_array($tipo, [Gruppo::TIPO_CLASSE, Gruppo::TIPO_ATTIVITA], true)) {
            throw new DatiNonValidi('tipo: classe o attivita.');
        }

        return $tipo;
    }
}
