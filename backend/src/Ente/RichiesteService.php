<?php

namespace App\Ente;

use App\Account\Entity\User;
use App\Ente\Entity\Collegamento;
use App\Ente\Entity\FileCifrato;
use App\Ente\Entity\FirmaCodice;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\Referente;
use App\Ente\Entity\Richiesta;
use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\Enum\DestinatarioStato;
use App\Ente\Enum\ReferenteStato;
use App\Ente\Enum\RichiestaTipo;
use App\Scadenze\Entity\Scadenza;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Richieste dell'ente alle famiglie: invio (un destinatario per famiglia e
 * iscritto), verifica delle risposte, solleciti, annullamento.
 *
 * Le email alle famiglie dicono solo "hai una richiesta da <ente>": mai il
 * titolo né il contenuto.
 */
final class RichiesteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EnteAccess $access,
        private readonly Posta $posta,
        private readonly \App\Account\Push\PushNotifier $push,
        private readonly AuditLogger $audit,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * Chi verrebbe raggiunto: per ogni iscritto, le famiglie con un referente
     * collegato e i referenti senza account raggiungibili dal link sicuro.
     *
     * @param list<Iscritto> $iscritti
     *
     * @return array{iscritti: int, referenti: int, referentiCollegati: int, famiglie: int, viaLink: int, nonRaggiunti: list<string>, piano: list<array{iscritto: Iscritto, famiglie: array<string, array{famiglia: Tenant, collegamenti: list<Collegamento>}>, link: list<Referente>}>}
     */
    public function pianifica(array $iscritti): array
    {
        $piano = [];
        $referenti = 0;
        $collegati = 0;
        $famiglie = 0;
        $viaLink = 0;
        $nonRaggiunti = [];
        foreach ($iscritti as $iscritto) {
            $perFamiglia = [];
            $link = [];
            foreach ($iscritto->getReferenti() as $referente) {
                ++$referenti;
                $c = $this->em->getRepository(Collegamento::class)->findOneBy(['referente' => $referente]);
                if ($c instanceof Collegamento && $c->isAttivo()) {
                    ++$collegati;
                    $key = (string) $c->getFamiglia()->getId();
                    $perFamiglia[$key] ??= ['famiglia' => $c->getFamiglia(), 'collegamenti' => []];
                    $perFamiglia[$key]['collegamenti'][] = $c;
                } elseif (self::raggiungibileViaLink($referente)) {
                    // Senza account (o invito non ancora accettato): risponde dal link sicuro.
                    $link[] = $referente;
                }
            }
            if ([] === $perFamiglia && [] === $link) {
                $nonRaggiunti[] = $iscritto->getNomeCompleto();
            }
            $famiglie += \count($perFamiglia);
            $viaLink += \count($link);
            $piano[] = ['iscritto' => $iscritto, 'famiglie' => $perFamiglia, 'link' => $link];
        }

        return ['iscritti' => \count($iscritti), 'referenti' => $referenti, 'referentiCollegati' => $collegati, 'famiglie' => $famiglie, 'viaLink' => $viaLink, 'nonRaggiunti' => $nonRaggiunti, 'piano' => $piano];
    }

    /**
     * Il link va solo a chi ha un'email e non ha rifiutato: non a chi ha detto
     * "non sono io", ha rifiutato l'invito o ha interrotto il collegamento.
     */
    public static function raggiungibileViaLink(Referente $r): bool
    {
        return null !== $r->getEmail() && \in_array($r->getStato(), [ReferenteStato::DA_INVITARE, ReferenteStato::INVITATO], true);
    }

    /**
     * @param list<Iscritto> $iscritti
     */
    public function invia(Tenant $ente, ?User $actor, RichiestaTipo $tipo, string $titolo, ?string $istruzioni, ?\DateTimeImmutable $entroIl, string $firmatari, ?Gruppo $gruppo, array $iscritti, ?FileCifrato $allegato, ?RegoleRichiesta $regole = null): Richiesta
    {
        $this->access->assertVerificato($ente);
        if (RichiestaTipo::FIRMA === $tipo && null === $allegato) {
            throw new DatiNonValidi('file: per una richiesta di firma allega il PDF da firmare.');
        }
        if (!\in_array($firmatari, [Richiesta::FIRMATARI_TUTTI, Richiesta::FIRMATARI_UNO], true)) {
            throw new DatiNonValidi('firmatari: tutti o uno.');
        }
        if (null !== $entroIl && $entroIl < new \DateTimeImmutable('today')) {
            throw new DatiNonValidi('entroIl: la data è già passata.');
        }
        $piano = $this->pianifica($iscritti);
        if (0 === $piano['famiglie'] + $piano['viaLink']) {
            throw new Conflitto('Nessuna famiglia raggiungibile: i referenti di questi iscritti non hanno un\'email o non sono collegati.');
        }

        $richiesta = new Richiesta($ente, $tipo, $titolo, $istruzioni, $entroIl, $firmatari, $gruppo, $allegato, $actor);
        $richiesta->setNonRaggiunti($piano['nonRaggiunti']);
        if (null !== $regole) {
            $richiesta->impostaRegole($regole->modello, $regole->chiediValidita, $regole->rinnovoGiorni, $regole->conservazioneMesi, $regole->rinnovoDi);
            try {
                $richiesta->setModalitaFirma($regole->modalitaFirma);
            } catch (\InvalidArgumentException) {
                throw new DatiNonValidi('modalitaFirma: cartacea o codice.');
            }
            if ($richiesta->isFirmaConCodice() && 'application/pdf' !== $allegato?->getMimeType()) {
                throw new DatiNonValidi('file: la firma con codice richiede il documento in PDF.');
            }
        }
        $this->em->persist($richiesta);
        $daAvvisare = [];
        $link = [];
        foreach ($piano['piano'] as ['iscritto' => $iscritto, 'link' => $referentiLink]) {
            foreach ($referentiLink as $referente) {
                $d = RichiestaDestinatario::viaLink($richiesta, $referente);
                $this->em->persist($d);
                $link[] = $d;
            }
        }
        foreach ($piano['piano'] as ['iscritto' => $iscritto, 'famiglie' => $perFamiglia]) {
            foreach ($perFamiglia as ['famiglia' => $famiglia, 'collegamenti' => $collegamenti]) {
                $d = new RichiestaDestinatario($richiesta, $iscritto, $famiglia, array_map(static fn (Collegamento $c) => $c->getReferente(), $collegamenti));
                $this->em->persist($d);
                $owner = $collegamenti[0]->getAccettatoDa();
                // La scadenza va a chi ha accettato il collegamento, se è ancora nella famiglia.
                if (null !== $entroIl && null !== $owner && $this->membroAttivo($owner, $famiglia)) {
                    $prefisso = match ($tipo) {
                        RichiestaTipo::FIRMA => 'Firma',
                        RichiestaTipo::INVIO => 'Invia',
                        RichiestaTipo::PRESA_VISIONE => 'Presa visione',
                    };
                    $this->em->persist(Scadenza::perRichiesta($d, $owner, sprintf('%s: %s (%s)', $prefisso, $titolo, $ente->getName()), $entroIl, $collegamenti[0]->getPersona()));
                }
                foreach ($collegamenti as $c) {
                    if (null !== $c->getAccettatoDa()) {
                        $daAvvisare[$c->getAccettatoDa()->getEmail()] = $c->getAccettatoDa();
                    }
                }
            }
        }
        $this->em->flush();
        $this->audit->log('richiesta.inviata', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta', resourceId: (string) $richiesta->getId(), tenantId: $ente->getId(), context: ['tipo' => $tipo->value, 'famiglie' => $piano['famiglie'], 'via_link' => $piano['viaLink'], 'non_raggiunti' => \count($piano['nonRaggiunti'])], actor: $actor);
        foreach ($daAvvisare as $user) {
            $this->avvisa($user, $ente, 'nuova');
        }
        foreach ($link as $d) {
            $this->inviaLink($d, 'nuova');
        }

        return $richiesta;
    }

    /**
     * Nuovo link sicuro per un destinatario senza account (anche per solleciti e
     * rifirme: il link precedente smette di valere). Vale fino a 7 giorni dopo
     * la data "entro il", o 30 giorni se non c'è.
     */
    public function inviaLink(RichiestaDestinatario $d, string $motivo): void
    {
        $email = $d->getReferenteLink()?->getEmail();
        if (!$d->isViaLink() || null === $email) {
            return;
        }
        $token = TokenCasuale::nuovo();
        $entro = $d->getRichiesta()->getEntroIl();
        $scadenza = null !== $entro ? max($entro->modify('+7 days')->setTime(23, 59, 59), new \DateTimeImmutable('+3 days')) : new \DateTimeImmutable('+30 days');
        $d->nuovoLink(self::hashLink($token), $scadenza);
        $this->em->flush();
        $ente = $d->getRichiesta()->getTenant();
        $this->posta->invia($email, match ($motivo) {
                'rifirma' => sprintf('%s ti chiede di correggere una risposta', $ente->getName()),
                'sollecito' => sprintf('Promemoria: hai una richiesta da %s', $ente->getName()),
                default => sprintf('Hai una richiesta da %s', $ente->getName()),
            }, 'emails/richiesta_link.html.twig', ['nome' => $d->getReferenteLink()->getNome(), 'ente' => $ente->getName(), 'motivo' => $motivo, 'scadenza' => $scadenza, 'url' => rtrim($this->frontendUrl, '/').'/risposta?token='.$token]);
    }

    public static function hashLink(string $token): string
    {
        return hash('sha256', 'link|'.$token);
    }

    public function verifica(RichiestaDestinatario $d, bool $accetta, ?string $motivo, User $actor): void
    {
        if (DestinatarioStato::DA_VERIFICARE !== $d->getStato()) {
            throw new Conflitto('Questa risposta non è da verificare.');
        }
        if ($accetta) {
            $d->accetta();
            $this->scadenza($d)?->chiudi();
            $this->dopoCompletamento($d);
        } else {
            $motivo = null !== $motivo ? trim($motivo) : '';
            if ('' === $motivo) {
                throw new DatiNonValidi('motivo: spiega alla famiglia cosa correggere.');
            }
            $d->chiediRifirma(mb_substr($motivo, 0, 500));
            $this->scadenza($d)?->riapri();
        }
        $this->em->flush();
        $this->logEntrambi($accetta ? 'richiesta.accettata' : 'richiesta.da_rifirmare', $d, $actor);
        if (!$accetta) {
            foreach ($this->utentiFamiglia($d) as $user) {
                $this->avvisa($user, $d->getRichiesta()->getTenant(), 'rifirma');
            }
            $this->inviaLink($d, 'rifirma');
        }
    }

    /**
     * @param list<RichiestaDestinatario>|null $soloQuesti null = tutti quelli che devono ancora rispondere
     */
    public function sollecita(Richiesta $richiesta, ?array $soloQuesti, User $actor): int
    {
        if ($richiesta->isAnnullata()) {
            throw new Conflitto('La richiesta è annullata.');
        }
        $utenti = [];
        $n = 0;
        foreach ($soloQuesti ?? $richiesta->getDestinatari()->toArray() as $d) {
            if (!$d->getStato()->isDaFare()) {
                continue;
            }
            $d->segnaSollecitata();
            ++$n;
            foreach ($this->utentiFamiglia($d) as $u) {
                $utenti[$u->getEmail()] = $u;
            }
            if ($d->isViaLink()) {
                $this->inviaLink($d, 'sollecito');
            }
        }
        $this->em->flush();
        foreach ($utenti as $u) {
            $this->avvisa($u, $richiesta->getTenant(), 'sollecito');
        }
        $this->audit->log('richiesta.sollecitata', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta', resourceId: (string) $richiesta->getId(), tenantId: $richiesta->getTenant()->getId(), context: ['destinatari' => $n], actor: $actor);

        return $n;
    }

    public function annulla(Richiesta $richiesta, User $actor): void
    {
        if ($richiesta->isAnnullata()) {
            throw new Conflitto('La richiesta è già annullata.');
        }
        $richiesta->annulla();
        foreach ($richiesta->getDestinatari() as $d) {
            if (!$d->getStato()->isConclusa()) {
                $d->annulla();
                $this->rimuoviScadenza($d);
            }
        }
        $this->em->flush();
        $this->audit->log('richiesta.annullata', AuditOutcome::SUCCESS, resourceType: 'ente_richiesta', resourceId: (string) $richiesta->getId(), tenantId: $richiesta->getTenant()->getId(), actor: $actor);
    }

    /**
     * Stato per iscritto e totali (come li vede l'ente).
     *
     * @return array{totali: array<string, int>, iscritti: list<array<string, mixed>>}
     */
    public function avanzamento(Richiesta $richiesta): array
    {
        /** @var array<string, list<RichiestaDestinatario>> $perIscritto */
        $perIscritto = [];
        foreach ($richiesta->getDestinatari() as $d) {
            $perIscritto[(string) $d->getIscritto()->getId()][] = $d;
        }
        $totali = ['iscritti' => \count($perIscritto) + \count($richiesta->getNonRaggiunti()), 'completate' => 0, 'daVerificare' => 0, 'rifiutate' => 0, 'mancanti' => 0, 'nonRaggiunti' => \count($richiesta->getNonRaggiunti())];
        // Firme con codice già apposte, anche quando non hanno ancora firmato tutti.
        $firmeCodice = [];
        if ($richiesta->isFirmaConCodice()) {
            $rows = $this->em->createQuery('SELECT IDENTITY(f.destinatario) AS d, IDENTITY(f.referente) AS r, f.firmataIl AS il FROM '.FirmaCodice::class.' f JOIN f.destinatario x WHERE x.richiesta = :r AND f.firmataIl IS NOT NULL')
                ->setParameter('r', $richiesta->getId(), 'uuid')->getArrayResult();
            foreach ($rows as $row) {
                $firmeCodice[(string) $row['d']][(string) $row['r']] = $row['il'];
            }
        }
        $righe = [];
        foreach ($perIscritto as $destinatari) {
            $stati = array_map(static fn (RichiestaDestinatario $d) => $d->getStato(), $destinatari);
            $attivi = array_filter($stati, static fn ($s) => DestinatarioStato::ANNULLATA !== $s);
            $completate = array_filter($stati, static fn ($s) => DestinatarioStato::COMPLETATA === $s);
            $stato = match (true) {
                $richiesta->isAnnullata() => 'annullata',
                $richiesta->bastaUno() ? [] !== $completate : (\count($completate) === \count($attivi) && [] !== $attivi) => 'completata',
                \in_array(DestinatarioStato::DA_VERIFICARE, $stati, true) => 'da_verificare',
                \in_array(DestinatarioStato::RIFIUTATA, $stati, true) => 'rifiutata',
                default => 'mancante',
            };
            match ($stato) {
                'completata' => ++$totali['completate'],
                'da_verificare' => ++$totali['daVerificare'],
                'rifiutata' => ++$totali['rifiutate'],
                'mancante' => ++$totali['mancanti'],
                default => null,
            };
            $iscritto = $destinatari[0]->getIscritto();
            $righe[] = [
                'iscritto' => ['id' => (string) $iscritto->getId(), 'nome' => $iscritto->getNomeCompleto()],
                'stato' => $stato,
                'destinatari' => array_map(static fn (RichiestaDestinatario $d) => [
                    'id' => (string) $d->getId(),
                    'stato' => $d->getStato()->value,
                    'scaduta' => $d->isScaduta(),
                    'referenti' => array_values(array_map(static fn ($r) => [
                        'id' => (string) $r->getId(),
                        'nome' => $r->getNomeCompleto(),
                        'firmato' => \in_array((string) $r->getId(), $d->getFirmatari(), true) || isset($firmeCodice[(string) $d->getId()][(string) $r->getId()]),
                        'firmatoConCodiceIl' => isset($firmeCodice[(string) $d->getId()][(string) $r->getId()]) ? $firmeCodice[(string) $d->getId()][(string) $r->getId()]->format(\DATE_ATOM) : null,
                    ], $d->getReferenti()->toArray())),
                    'messaggioFamiglia' => $d->getMessaggioFamiglia(),
                    'motivoEnte' => $d->getMotivoEnte(),
                    'vistaIl' => $d->getVistaIl()?->format(\DATE_ATOM),
                    'rispostaIl' => $d->getRispostaIl()?->format(\DATE_ATOM),
                    'sollecitataIl' => $d->getSollecitataIl()?->format(\DATE_ATOM),
                    'haFile' => null !== $d->getRisposta(),
                    'viaLink' => $d->isViaLink(),
                    'validoFino' => $d->getValidoFino()?->format('Y-m-d'),
                    'fileRimossoIl' => $d->getFileRimossoIl()?->format(\DATE_ATOM),
                ], $destinatari),
            ];
        }
        usort($righe, static fn ($a, $b) => strcmp($a['iscritto']['nome'], $b['iscritto']['nome']));

        return ['totali' => $totali, 'iscritti' => $righe];
    }

    /** Basta un referente: le altre famiglie dello stesso iscritto non devono più rispondere. */
    public function dopoCompletamento(RichiestaDestinatario $d): void
    {
        if (!$d->getRichiesta()->bastaUno()) {
            return;
        }
        foreach ($d->getRichiesta()->getDestinatari() as $altro) {
            if ($altro !== $d && $altro->getIscritto() === $d->getIscritto() && $altro->getStato()->isDaFare()) {
                $altro->annulla();
                $this->rimuoviScadenza($altro);
            }
        }
    }

    public function scadenza(RichiestaDestinatario $d): ?Scadenza
    {
        return $this->em->getRepository(Scadenza::class)->findOneBy(['richiesta' => $d]);
    }

    public function rimuoviScadenza(RichiestaDestinatario $d): void
    {
        $s = $this->scadenza($d);
        if (null !== $s) {
            $this->em->remove($s);
        }
    }

    /** @return list<User> */
    public function utentiFamiglia(RichiestaDestinatario $d): array
    {
        if (null === $d->getFamiglia()) {
            return [];
        }
        $utenti = [];
        foreach ($d->getReferenti() as $r) {
            $c = $this->em->getRepository(Collegamento::class)->findOneBy(['referente' => $r]);
            if ($c instanceof Collegamento && $c->isAttivo() && null !== $c->getAccettatoDa() && $c->getFamiglia()->getId()->equals($d->getFamiglia()->getId())) {
                $utenti[$c->getAccettatoDa()->getEmail()] = $c->getAccettatoDa();
            }
        }

        return array_values($utenti);
    }

    public function logEntrambi(string $action, RichiestaDestinatario $d, ?User $actor): void
    {
        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'ente_richiesta_destinatario', resourceId: (string) $d->getId(), tenantId: $d->getRichiesta()->getTenant()->getId(), context: ['richiesta' => (string) $d->getRichiesta()->getId()], actor: $actor);
        if (null !== $d->getFamiglia()) {
            $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'ente_richiesta_destinatario', resourceId: (string) $d->getId(), tenantId: $d->getFamiglia()->getId(), context: ['ente' => $d->getRichiesta()->getTenant()->getName()], actor: $actor);
        }
    }

    private function membroAttivo(User $user, Tenant $famiglia): bool
    {
        $m = $this->em->getRepository(Membership::class)->findOneBy(['user' => $user, 'tenant' => $famiglia]);

        return $m instanceof Membership && MembershipStatus::ACTIVE === $m->getStatus();
    }

    /** Email senza contenuto: "hai una richiesta da…". */
    private function avvisa(User $user, Tenant $ente, string $motivo): void
    {
        $oggetto = match ($motivo) {
            'rifirma' => sprintf('%s ti chiede di correggere una risposta', $ente->getName()),
            'sollecito' => sprintf('Promemoria: hai una richiesta da %s', $ente->getName()),
            default => sprintf('Hai una nuova richiesta da %s', $ente->getName()),
        };
        // Push con lo stesso testo dell'oggetto: nessun titolo della richiesta.
        $this->push->notifica($user, 'CartellaViva', $oggetto, '/richieste');
        $this->posta->invia($user->getEmail(), $oggetto, 'emails/richiesta_avviso.html.twig', ['nome' => $user->getFirstName(), 'ente' => $ente->getName(), 'motivo' => $motivo, 'url' => rtrim($this->frontendUrl, '/').'/richieste']);
    }
}
