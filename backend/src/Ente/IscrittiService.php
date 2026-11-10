<?php

namespace App\Ente;

use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\Referente;
use App\Ente\Enum\ReferenteStato;
use App\Ente\Repository\IscrittoRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Iscritti dell'ente e loro referenti. Rispetta l'ambito per gruppo
 * dell'operatore (EnteAccess): vede e tocca solo i gruppi che gli competono.
 *
 * @phpstan-type DatiReferente array{nome: string, cognome: string, email: ?string, relazione: ?string}
 */
final class IscrittiService
{
    public const FILTRI = ['senza_email', 'da_invitare', 'invitati', 'non_collegati'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IscrittoRepository $iscritti,
        private readonly EnteAccess $access,
        private readonly IscrittiImport $import,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Iscritti visibili all'operatore, con i totali calcolati prima del [filtro].
     *
     * @return array{iscritti: list<Iscritto>, totali: array{iscritti: int, referenti: int, referentiCollegati: int, iscrittiSenzaEmail: int}}
     */
    public function elenco(Tenant $ente, ?Gruppo $gruppo, string $testo, string $filtro): array
    {
        $visibili = array_values(array_filter($this->iscritti->cerca($ente, $gruppo, $testo), fn (Iscritto $i) => $this->access->puoVedereIscritto($i)));

        return [
            'iscritti' => array_values(array_filter($visibili, static fn (Iscritto $i) => self::passaFiltro($i, $filtro))),
            'totali' => self::totali($visibili),
        ];
    }

    /**
     * @param list<Gruppo>        $gruppi
     * @param list<DatiReferente> $referenti
     */
    public function crea(Tenant $ente, string $nome, string $cognome, ?\DateTimeImmutable $dataNascita, array $gruppi, array $referenti): Iscritto
    {
        self::gruppiValidi($gruppi);
        $iscritto = new Iscritto($ente, $nome, $cognome, $dataNascita);
        foreach ($gruppi as $g) {
            $iscritto->iscriviA($g);
        }
        foreach ($referenti as $r) {
            $this->nuovoReferente($iscritto, $r);
        }
        $this->em->persist($iscritto);
        $this->em->flush();
        $this->audit->log('iscritto.creato', AuditOutcome::SUCCESS, resourceType: 'ente_iscritto', resourceId: (string) $iscritto->getId(), tenantId: $ente->getId());

        return $iscritto;
    }

    /**
     * @param list<Gruppo>|null $gruppi null = gruppi invariati. Si toccano solo i
     *                                  gruppi che l'operatore vede: gli altri restano.
     */
    public function aggiorna(Iscritto $iscritto, string $nome, string $cognome, ?\DateTimeImmutable $dataNascita, ?array $gruppi): void
    {
        $iscritto->aggiorna($nome, $cognome, $dataNascita);
        if (null !== $gruppi) {
            self::gruppiValidi($gruppi);
            foreach ($iscritto->getGruppi()->toArray() as $g) {
                if ($this->access->puoVedereGruppo($g) && !$g->getAnno()->isArchiviato() && !\in_array($g, $gruppi, true)) {
                    $iscritto->togliDa($g);
                }
            }
            foreach ($gruppi as $g) {
                $iscritto->iscriviA($g);
            }
        }
        $this->em->flush();
    }

    public function elimina(Iscritto $iscritto): void
    {
        $this->audit->log('iscritto.eliminato', AuditOutcome::SUCCESS, resourceType: 'ente_iscritto', resourceId: (string) $iscritto->getId(), tenantId: $iscritto->getTenant()->getId());
        $this->em->remove($iscritto);
        $this->em->flush();
    }

    /** @param DatiReferente $dati */
    public function aggiungiReferente(Iscritto $iscritto, array $dati): Referente
    {
        $referente = $this->nuovoReferente($iscritto, $dati);
        $this->em->flush();

        return $referente;
    }

    /** @param DatiReferente $dati */
    public function aggiornaReferente(Referente $referente, array $dati): void
    {
        $referente->aggiorna($dati['nome'], $dati['cognome'], $dati['email'], $dati['relazione']);
        $this->em->flush();
    }

    public function eliminaReferente(Referente $referente): void
    {
        $referente->getIscritto()->getReferenti()->removeElement($referente);
        $this->em->remove($referente);
        $this->em->flush();
    }

    /**
     * Import da CSV nei gruppi dell'anno in corso visibili all'operatore. Con
     * $applica false restituisce solo l'anteprima; gli inviti non partono mai.
     *
     * @return array<string, mixed>
     */
    public function importa(Tenant $ente, string $csv, bool $applica): array
    {
        $anno = $this->em->getRepository(AnnoEnte::class)->corrente($ente);
        $gruppi = [];
        foreach (null === $anno ? [] : $this->em->getRepository(Gruppo::class)->findBy(['anno' => $anno]) as $g) {
            if ($this->access->puoVedereGruppo($g)) {
                $gruppi[mb_strtolower($g->getNome())] = $g;
            }
        }
        $esito = $this->import->importa($ente, $csv, $gruppi, $applica);
        if ($applica) {
            $this->audit->log('iscritti.importati', AuditOutcome::SUCCESS, resourceType: 'ente_iscritto', tenantId: $ente->getId(), context: ['nuovi' => $esito['nuovi'], 'esistenti' => $esito['esistenti'], 'errori' => $esito['errori']]);
        }

        return $esito;
    }

    /** @return list<Referente> tutti i referenti degli iscritti del gruppo */
    public function referentiDelGruppo(Gruppo $gruppo): array
    {
        $referenti = [];
        foreach ($this->iscritti->delGruppo($gruppo) as $i) {
            foreach ($i->getReferenti() as $r) {
                $referenti[] = $r;
            }
        }

        return $referenti;
    }

    /** @param DatiReferente $dati */
    private function nuovoReferente(Iscritto $iscritto, array $dati): Referente
    {
        $referente = new Referente($iscritto, $dati['nome'], $dati['cognome'], $dati['email'], $dati['relazione']);
        $this->em->persist($referente);

        return $referente;
    }

    /** @param list<Gruppo> $gruppi */
    private static function gruppiValidi(array $gruppi): void
    {
        if ([] === $gruppi) {
            throw new DatiNonValidi('gruppiIds: indica almeno un gruppo.');
        }
        foreach ($gruppi as $g) {
            if ($g->getAnno()->isArchiviato()) {
                throw new DatiNonValidi('gruppiIds: i gruppi degli anni archiviati sono in sola lettura.');
            }
        }
    }

    /**
     * @param list<Iscritto> $iscritti
     *
     * @return array{iscritti: int, referenti: int, referentiCollegati: int, iscrittiSenzaEmail: int}
     */
    private static function totali(array $iscritti): array
    {
        $ref = 0;
        $collegati = 0;
        $senzaEmail = 0;
        foreach ($iscritti as $i) {
            $conEmail = false;
            foreach ($i->getReferenti() as $r) {
                ++$ref;
                if (ReferenteStato::COLLEGATO === $r->getStato()) {
                    ++$collegati;
                }
                $conEmail = $conEmail || null !== $r->getEmail();
            }
            if (!$conEmail) {
                ++$senzaEmail;
            }
        }

        return ['iscritti' => \count($iscritti), 'referenti' => $ref, 'referentiCollegati' => $collegati, 'iscrittiSenzaEmail' => $senzaEmail];
    }

    private static function passaFiltro(Iscritto $i, string $filtro): bool
    {
        $stati = array_map(static fn (Referente $r) => $r->getStato(), $i->getReferenti()->toArray());
        $email = array_filter($i->getReferenti()->toArray(), static fn (Referente $r) => null !== $r->getEmail());

        return match ($filtro) {
            'senza_email' => [] === $email,
            'da_invitare' => \in_array(ReferenteStato::DA_INVITARE, $stati, true),
            'invitati' => \in_array(ReferenteStato::INVITATO, $stati, true),
            'non_collegati' => [] !== array_filter($stati, static fn ($s) => ReferenteStato::COLLEGATO !== $s),
            default => true,
        };
    }
}
