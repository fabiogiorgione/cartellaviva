<?php

namespace App\Portale;

use App\Account\Entity\User;
use App\Ente\Entity\EnteProfilo;
use App\Portale\Entity\Idea;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\TroppiTentativi;
use App\Shared\Mail\Posta;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Portale delle idee: invio dagli utenti con account, gestione dal portale,
 * avviso all'autore quando cambia qualcosa e riepilogo giornaliero al
 * superadmin. Le email non contengono mai il testo delle idee né le risposte.
 */
final class IdeeService
{
    /** Idee che un utente può inviare in 24 ore. */
    public const LIMITE_GIORNALIERO = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipRepository $memberships,
        private readonly AuditLogger $audit,
        private readonly Posta $posta,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    /** @param array{tipo?: ?string, area?: ?string, testo?: ?string, contattabile?: bool, tenantId?: ?string, piattaforma?: ?string, versioneApp?: ?string} $dati */
    public function invia(User $autore, array $dati): Idea
    {
        $tipo = $dati['tipo'] ?? null;
        if (!\in_array($tipo, Idea::TIPI, true)) {
            throw new DatiNonValidi('Scegli se è un miglioramento o una nuova funzionalità.');
        }
        $area = $dati['area'] ?? null;
        if (null !== $area && !\in_array($area, Idea::AREE, true)) {
            throw new DatiNonValidi('Area non valida.');
        }
        $testo = trim((string) ($dati['testo'] ?? ''));
        $lunghezza = mb_strlen($testo);
        if ($lunghezza < Idea::TESTO_MIN || $lunghezza > Idea::TESTO_MAX) {
            throw new DatiNonValidi(sprintf('Descrivi l\'idea in %d-%d caratteri.', Idea::TESTO_MIN, Idea::TESTO_MAX));
        }
        $recenti = (int) $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(Idea::class, 'i')
            ->where('i.autore = :u AND i.createdAt > :da')
            ->setParameter('u', $autore)->setParameter('da', new \DateTimeImmutable('-24 hours'))
            ->getQuery()->getSingleScalarResult();
        if ($recenti >= self::LIMITE_GIORNALIERO) {
            throw new TroppiTentativi(sprintf('Puoi inviare al massimo %d idee al giorno. Grazie della partecipazione!', self::LIMITE_GIORNALIERO));
        }
        $piattaforma = $dati['piattaforma'] ?? null;
        $versione = isset($dati['versioneApp']) && preg_match('/^[0-9A-Za-z.+\-]{1,20}$/', (string) $dati['versioneApp']) ? (string) $dati['versioneApp'] : null;

        $idea = new Idea(
            $autore,
            $tipo,
            $area,
            $testo,
            (bool) ($dati['contattabile'] ?? true),
            $this->tipoSpazio($autore, $dati['tenantId'] ?? null),
            \in_array($piattaforma, Idea::PIATTAFORME, true) ? $piattaforma : null,
            $versione,
        );
        $this->em->persist($idea);
        $this->em->flush();
        $this->audit->log('idea.inviata', AuditOutcome::SUCCESS, resourceType: 'Idea', resourceId: (string) $idea->getId(), context: ['tipo' => $tipo, 'area' => $area]);

        return $idea;
    }

    /**
     * Stato e risposta pubblica avvisano gli autori (della principale e dei
     * duplicati); la nota interna no.
     *
     * @param array{stato?: ?string, rispostaPubblica?: ?string, notaInterna?: ?string} $modifiche solo le chiavi presenti vengono applicate
     */
    public function aggiorna(Idea $idea, User $supporto, array $modifiche): Idea
    {
        $avvisa = false;
        if (\array_key_exists('stato', $modifiche) && null !== $modifiche['stato'] && $modifiche['stato'] !== $idea->getStato()) {
            if (null !== $idea->getDuplicatoDi()) {
                throw new Conflitto('È un duplicato: lo stato si cambia sull\'idea principale.');
            }
            try {
                $idea->setStato($modifiche['stato']);
            } catch (\InvalidArgumentException $e) {
                throw new DatiNonValidi($e->getMessage());
            }
            // "Letta" non è una novità che valga un'email.
            $avvisa = Idea::LETTA !== $modifiche['stato'];
        }
        if (\array_key_exists('rispostaPubblica', $modifiche) && $modifiche['rispostaPubblica'] !== $idea->getRispostaPubblica()) {
            $idea->setRispostaPubblica($modifiche['rispostaPubblica']);
            $avvisa = $avvisa || null !== $modifiche['rispostaPubblica'];
        }
        if (\array_key_exists('notaInterna', $modifiche)) {
            $idea->setNotaInterna($modifiche['notaInterna']);
        }
        $this->em->flush();
        $this->audit->log('idea.aggiornata', AuditOutcome::SUCCESS, resourceType: 'Idea', resourceId: (string) $idea->getId(), context: ['stato' => $idea->getStato()], actor: $supporto);
        if ($avvisa) {
            $this->avvisaAutori($idea);
        }

        return $idea;
    }

    /** Unisce $idea (e i suoi duplicati) a $principale, che conta come "richiesta da N". */
    public function unisci(Idea $idea, Idea $principale, User $supporto): void
    {
        $principale = $principale->getPrincipale();
        if ($idea->getId()->equals($principale->getId())) {
            throw new Conflitto('Un\'idea non si unisce a se stessa.');
        }
        foreach ($this->duplicati($idea) as $d) {
            $d->unisciA($principale);
        }
        $idea->unisciA($principale);
        $this->em->flush();
        $this->audit->log('idea.unita', AuditOutcome::SUCCESS, resourceType: 'Idea', resourceId: (string) $idea->getId(), context: ['principale' => (string) $principale->getId()], actor: $supporto);
    }

    /** Separa un duplicato unito per errore. */
    public function separa(Idea $idea, User $supporto): void
    {
        $idea->unisciA(null);
        $this->em->flush();
        $this->audit->log('idea.separata', AuditOutcome::SUCCESS, resourceType: 'Idea', resourceId: (string) $idea->getId(), actor: $supporto);
    }

    public function elimina(Idea $idea, User $admin): void
    {
        foreach ($this->duplicati($idea) as $d) {
            $d->unisciA(null);
        }
        $this->audit->log('idea.eliminata', AuditOutcome::SUCCESS, resourceType: 'Idea', resourceId: (string) $idea->getId(), actor: $admin);
        $this->em->remove($idea);
        $this->em->flush();
    }

    /** @return list<Idea> */
    public function duplicati(Idea $idea): array
    {
        return $this->em->getRepository(Idea::class)->findBy(['duplicatoDi' => $idea], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /** @return array<string, int> id della principale => numero di duplicati */
    public function conteggioDuplicati(): array
    {
        $rows = $this->em->createQueryBuilder()->select('IDENTITY(i.duplicatoDi) AS p, COUNT(i.id) AS n')->from(Idea::class, 'i')
            ->where('i.duplicatoDi IS NOT NULL')->groupBy('i.duplicatoDi')->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['p']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Riepilogo giornaliero ai superadmin: solo quante idee nuove sono
     * arrivate, mai il testo. Nessuna email se non ce ne sono.
     *
     * @return array{idee: int, email: int}
     */
    public function inviaRiepilogo(): array
    {
        /** @var list<Idea> $nuove */
        $nuove = $this->em->createQueryBuilder()->select('i')->from(Idea::class, 'i')
            ->where('i.riepilogoInviatoIl IS NULL')->getQuery()->getResult();
        if ([] === $nuove) {
            return ['idee' => 0, 'email' => 0];
        }
        $ora = new \DateTimeImmutable();
        foreach ($nuove as $i) {
            $i->segnaRiepilogo($ora);
        }
        $admin = array_values(array_filter($this->em->getRepository(User::class)->findBy(['superAdmin' => true]), static fn (User $u) => $u->isActive()));
        $funzionalita = \count(array_filter($nuove, static fn (Idea $i) => Idea::FUNZIONALITA === $i->getTipo()));
        foreach ($admin as $u) {
            $this->posta->invia($u->getEmail(), 1 === \count($nuove) ? 'Portale delle idee: 1 nuova idea' : sprintf('Portale delle idee: %d nuove idee', \count($nuove)), 'emails/idee_riepilogo.html.twig', [
                    'nome' => $u->getFirstName(),
                    'totale' => \count($nuove),
                    'funzionalita' => $funzionalita,
                    'miglioramenti' => \count($nuove) - $funzionalita,
                    'url' => rtrim($this->frontendUrl, '/').'/admin/idee',
                ]);
        }
        $this->em->flush();
        $result = ['idee' => \count($nuove), 'email' => \count($admin)];
        $this->logger->info('Riepilogo idee: {result}', ['result' => json_encode($result)]);

        return $result;
    }

    private function avvisaAutori(Idea $idea): void
    {
        $destinatari = [];
        foreach ([$idea, ...$this->duplicati($idea)] as $i) {
            $u = $i->getAutore();
            if (null !== $u && $u->isActive()) {
                $destinatari[$u->getId()->toRfc4122()] = $u;
            }
        }
        foreach ($destinatari as $u) {
            $this->posta->invia($u->getEmail(), 'La tua idea ha un aggiornamento', 'emails/idea_aggiornamento.html.twig', ['nome' => $u->getFirstName(), 'url' => rtrim($this->frontendUrl, '/').'/idee']);
        }
    }

    /** Solo il tipo dello spazio da cui si scrive, mai quale: e solo se l'utente ne fa parte. */
    private function tipoSpazio(User $autore, mixed $tenantId): ?string
    {
        if (!\is_string($tenantId) || !Uuid::isValid($tenantId)) {
            return null;
        }
        $tenant = $this->em->find(Tenant::class, Uuid::fromString($tenantId));
        if (!$tenant instanceof Tenant || null === $this->memberships->findActiveWithPermissions($autore, $tenant)) {
            return null;
        }
        if (null !== $this->em->getRepository(EnteProfilo::class)->findOneBy(['tenant' => $tenant])) {
            return 'ente';
        }

        return TenantType::PRIVATO === $tenant->getType() ? 'famiglia' : 'azienda';
    }
}
