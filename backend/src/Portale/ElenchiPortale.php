<?php

namespace App\Portale;

use App\Account\AccountService;
use App\Account\Entity\User;
use App\Documenti\Repository\DocumentoRepository;
use App\Ente\Entity\EnteProfilo;
use App\Ente\Entity\Referente;
use App\Ente\Enum\EnteStato;
use App\Ente\Enum\ReferenteStato;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Elenchi del portale (famiglie ed enti, utenti, team) e le loro righe: solo
 * metadati, mai i contenuti delle famiglie. I filtri arrivano come array di
 * stringhe (i parametri della richiesta).
 */
final class ElenchiPortale
{
    public const PER_PAGINA = [10, 25, 50, 100];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProcedureService $procedure,
        private readonly MembershipRepository $memberships,
        private readonly DocumentoRepository $documenti,
    ) {
    }

    /**
     * Ricerca semplice (fino a 300): tipo=privato|azienda, q=nome, stato=valore di "stato" della riga.
     *
     * @param array<string, mixed> $f
     *
     * @return list<array<string, mixed>>
     */
    public function tenants(array $f): array
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Tenant::class, 't')->orderBy('t.name', 'ASC')->setMaxResults(300);
        if (\in_array($tipo = (string) ($f['tipo'] ?? ''), ['privato', 'azienda'], true)) {
            $qb->andWhere('t.type = :tipo')->setParameter('tipo', $tipo);
        }
        if ('' !== ($q = trim((string) ($f['q'] ?? '')))) {
            $qb->andWhere('LOWER(t.name) LIKE :q')->setParameter('q', '%'.mb_strtolower($q).'%');
        }
        $orfane = $this->procedure->famiglieSenzaProprietario();
        $righe = array_map(fn (Tenant $t) => $this->rigaTenant($t, \in_array($t, $orfane, true)), $qb->getQuery()->getResult());
        $stato = (string) ($f['stato'] ?? '');

        return '' === $stato ? $righe : array_values(array_filter($righe, static fn ($r) => $r['stato'] === $stato));
    }

    /** @return array<string, mixed> metadati della famiglia o dell'ente, con l'elenco dei membri */
    public function dettaglioTenant(Tenant $tenant): array
    {
        $profilo = $this->em->getRepository(EnteProfilo::class)->findOneBy(['tenant' => $tenant]);

        // Nel dettaglio "membri" è l'elenco (nella lista è il numero).
        return [
            'ente' => null === $profilo ? null : [
                'tipo' => $profilo->getTipo()->value,
                'codiceFiscale' => $profilo->getCodiceFiscale(),
                'codiceMeccanografico' => $profilo->getCodiceMeccanografico(),
                'numeroRunts' => $profilo->getNumeroRunts(),
                'emailIstituzionale' => $profilo->getEmailIstituzionale(),
                'sedeLegale' => $profilo->getSedeLegale(),
                'notaPortale' => $profilo->getNotaPortale(),
                'verificatoIl' => $profilo->getVerificatoIl()?->format(\DATE_ATOM),
            ],
            'membri' => array_map(static fn (Membership $m) => [
                'membershipId' => (string) $m->getId(),
                'userId' => (string) $m->getUser()->getId(),
                'nome' => $m->getUser()->getFullName(),
                'email' => $m->getUser()->getEmail(),
                'ruolo' => $m->getRole()->getName(),
                'ruoloId' => (string) $m->getRole()->getId(),
                'proprietario' => $m->getRole()->isOwnerRole(),
                'stato' => $m->getStatus()->value,
                'utenteAttivo' => $m->getUser()->isActive(),
                'inAttesaAttivazione' => AccountService::inAttesaDiAttivazione($m->getUser()),
            ], $this->memberships->findForTenant($tenant)),
        ] + $this->rigaTenant($tenant, \in_array($tenant, $this->procedure->famiglieSenzaProprietario(), true));
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array{totale: int, righe: list<array<string, mixed>>}
     */
    public function paginaTenant(array $f, int $pagina, int $perPagina): array
    {
        $orfane = $this->procedure->famiglieSenzaProprietario();
        $qb = $this->filtriTenant($f, $orfane);
        $totale = (int) (clone $qb)->select('COUNT(t.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $tenants = $qb->setFirstResult(($pagina - 1) * $perPagina)->setMaxResults($perPagina)->getQuery()->getResult();

        return ['totale' => $totale, 'righe' => array_map(fn (Tenant $t) => $this->rigaTenant($t, \in_array($t, $orfane, true)), $tenants)];
    }

    /**
     * Righe per l'esportazione (fino a 10.000).
     *
     * @param array<string, mixed> $f
     *
     * @return list<array<string, mixed>>
     */
    public function tuttiTenant(array $f): array
    {
        $orfane = $this->procedure->famiglieSenzaProprietario();

        return array_map(fn (Tenant $t) => $this->rigaTenant($t, \in_array($t, $orfane, true)), $this->filtriTenant($f, $orfane)->setMaxResults(10000)->getQuery()->getResult());
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array{totale: int, righe: list<array<string, mixed>>}
     */
    public function paginaUtenti(array $f, int $pagina, int $perPagina): array
    {
        $qb = $this->filtriUtenti($f);
        $totale = (int) (clone $qb)->select('COUNT(u.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<User> $users */
        $users = $qb->setFirstResult(($pagina - 1) * $perPagina)->setMaxResults($perPagina)->getQuery()->getResult();

        return ['totale' => $totale, 'righe' => $this->righeUtenti($users)];
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return list<array<string, mixed>>
     */
    public function tuttiUtenti(array $f): array
    {
        return $this->righeUtenti($this->filtriUtenti($f)->setMaxResults(10000)->getQuery()->getResult());
    }

    /** @return list<array<string, mixed>> utenti per email o nome (almeno 2 caratteri), fino a 50 */
    public function cercaUtenti(string $testo): array
    {
        $q = mb_strtolower(trim($testo));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $users = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')
            ->where('LOWER(u.email) LIKE :q OR LOWER(CONCAT(u.firstName, \' \', u.lastName)) LIKE :q')
            ->setParameter('q', '%'.$q.'%')->orderBy('u.email', 'ASC')->setMaxResults(50)->getQuery()->getResult();

        return array_map(fn (User $u) => $this->rigaUtente($u, false), $users);
    }

    /** @return list<array<string, mixed>> superadmin e chi ha un ruolo di piattaforma */
    public function team(): array
    {
        $users = $this->em->createQuery('SELECT u FROM '.User::class.' u WHERE u.superAdmin = true OR u.platformRole IS NOT NULL ORDER BY u.email')->getResult();

        return array_map(fn (User $u) => $this->rigaUtente($u, false), $users);
    }

    /** @return array<string, mixed> */
    public function rigaTenant(Tenant $t, bool $orfana): array
    {
        $membri = $this->memberships->findForTenant($t);
        $profilo = $this->em->getRepository(EnteProfilo::class)->findOneBy(['tenant' => $t]);
        $stato = match (true) {
            $t->isInEliminazione() => 'in_eliminazione',
            $orfana => 'senza_proprietario',
            null !== $profilo => 'ente_'.$profilo->getStato()->value,
            default => 'attivo',
        };

        return [
            'id' => (string) $t->getId(),
            'nome' => $t->getName(),
            'tipo' => $t->getType()->value,
            'membri' => \count(array_filter($membri, static fn (Membership $m) => MembershipStatus::ACTIVE === $m->getStatus())),
            'proprietari' => array_values(array_map(static fn (Membership $m) => $m->getUser()->getFullName(), array_filter($membri, static fn (Membership $m) => $m->isProprietarioAttivo()))),
            'spazioBytes' => $this->documenti->totalBytes($t),
            'createdAt' => $t->getCreatedAt()->format(\DATE_ATOM),
            'eliminazioneProgrammataIl' => $t->getEliminazioneProgrammataIl()?->format(\DATE_ATOM),
            'stato' => $stato,
        ];
    }

    /** @return array<string, mixed> */
    public function rigaUtente(User $u, bool $dettaglio): array
    {
        $base = [
            'id' => (string) $u->getId(),
            'email' => $u->getEmail(),
            'nome' => $u->getFullName(),
            'attivo' => $u->isActive(),
            'inAttesaAttivazione' => AccountService::inAttesaDiAttivazione($u),
            'ruoloPiattaforma' => $u->getPlatformRole(),
            'ultimoAccesso' => $u->getLastLoginAt()?->format(\DATE_ATOM),
        ];
        if (!$dettaglio) {
            return $base;
        }
        $referente = $this->em->createQuery('SELECT r FROM '.Referente::class.' r WHERE r.email = :e AND r.stato = :c')
            ->setParameter('e', $u->getEmail())->setParameter('c', ReferenteStato::COLLEGATO)->getResult();

        return $base + [
            'dueFattori' => $u->isTwoFactorEnabled(),
            'dueFattoriDal' => $u->getTotpEnabledAt()?->format(\DATE_ATOM),
            'dueFattoriObbligatoria' => $u->isTwoFactorObbligatoria(),
            'google' => null !== $u->getGoogleSubject(),
            'createdAt' => $u->getCreatedAt()->format(\DATE_ATOM),
            'appartenenze' => array_map(static fn (Membership $m) => [
                'tenantId' => (string) $m->getTenant()->getId(),
                'tenant' => $m->getTenant()->getName(),
                'tipo' => $m->getTenant()->getType()->value,
                'ruolo' => $m->getRole()->getName(),
                'stato' => $m->getStatus()->value,
            ], $this->em->getRepository(Membership::class)->findBy(['user' => $u])),
            'referentePresso' => array_values(array_unique(array_map(static fn (Referente $r) => $r->getTenant()->getName(), $referente))),
        ];
    }

    /**
     * @param list<User> $users
     *
     * @return list<array<string, mixed>>
     */
    public function righeUtenti(array $users): array
    {
        $perUtente = [];
        if ([] !== $users) {
            foreach ($this->em->createQuery('SELECT m, t, r FROM '.Membership::class.' m JOIN m.tenant t JOIN m.role r WHERE m.user IN (:users) ORDER BY t.name')->setParameter('users', $users)->getResult() as $m) {
                $perUtente[(string) $m->getUser()->getId()][] = [
                    'tenantId' => (string) $m->getTenant()->getId(),
                    'tenant' => $m->getTenant()->getName(),
                    'tipo' => $m->getTenant()->getType()->value,
                    'ruolo' => $m->getRole()->getName(),
                    'stato' => $m->getStatus()->value,
                ];
            }
        }

        return array_map(fn (User $u) => $this->rigaUtente($u, false) + [
            'nomeProprio' => $u->getFirstName(),
            'cognome' => $u->getLastName(),
            'stato' => match (true) {
                $u->isEliminato() => 'eliminato',
                !$u->isActive() => 'bloccato',
                AccountService::inAttesaDiAttivazione($u) => 'in_attesa',
                default => 'attivo',
            },
            'dueFattori' => $u->isTwoFactorEnabled(),
            'createdAt' => $u->getCreatedAt()->format(\DATE_ATOM),
            'appartenenze' => $perUtente[(string) $u->getId()] ?? [],
        ], $users);
    }

    /**
     * @param array<string, mixed> $f
     * @param list<Tenant>         $orfane
     */
    private function filtriTenant(array $f, array $orfane): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select('t')->from(Tenant::class, 't');
        if ('' !== ($nome = mb_strtolower(trim((string) ($f['nome'] ?? ''))))) {
            $qb->andWhere('LOWER(t.name) LIKE :nome')->setParameter('nome', '%'.$nome.'%');
        }
        if (\in_array($tipo = (string) ($f['tipo'] ?? ''), ['privato', 'azienda'], true)) {
            $qb->andWhere('t.type = :tipo')->setParameter('tipo', $tipo);
        }
        $stato = (string) ($f['stato'] ?? '');
        $soloOrfane = 'senza_proprietario' === $stato;
        if ($soloOrfane || 'attivo' === $stato) {
            if ([] === $orfane) {
                $qb->andWhere($soloOrfane ? '1 = 0' : '1 = 1');
            } else {
                $qb->andWhere($soloOrfane ? 't IN (:orfane)' : 't NOT IN (:orfane)')->setParameter('orfane', $orfane);
            }
        }
        if ('attivo' === $stato) {
            $qb->andWhere('t.eliminazioneProgrammataIl IS NULL');
        } elseif ('in_eliminazione' === $stato) {
            $qb->andWhere('t.eliminazioneProgrammataIl IS NOT NULL');
        } elseif (str_starts_with($stato, 'ente_') && null !== ($enteStato = EnteStato::tryFrom(substr($stato, 5)))) {
            $qb->andWhere('EXISTS (SELECT p.id FROM '.EnteProfilo::class.' p WHERE p.tenant = t AND p.stato = :enteStato)')->setParameter('enteStato', $enteStato);
        }
        if ('' !== ($membro = mb_strtolower(trim((string) ($f['membro'] ?? ''))))) {
            $qb->andWhere('EXISTS (SELECT m.id FROM '.Membership::class.' m JOIN m.user mu WHERE m.tenant = t AND (LOWER(mu.email) LIKE :membro OR LOWER(CONCAT(mu.firstName, \' \', mu.lastName)) LIKE :membro))')
                ->setParameter('membro', '%'.$membro.'%');
        }
        $verso = 'desc' === ($f['verso'] ?? null) ? 'DESC' : 'ASC';

        return $qb->orderBy('creato' === ($f['ordina'] ?? null) ? 't.createdAt' : 't.name', $verso)->addOrderBy('t.id', 'ASC');
    }

    /** @param array<string, mixed> $f */
    private function filtriUtenti(array $f): QueryBuilder
    {
        $eliminati = '%@'.User::DOMINIO_ELIMINATI;
        $qb = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')->setParameter('eliminati', $eliminati);
        if ('' !== ($nome = mb_strtolower(trim((string) ($f['nome'] ?? ''))))) {
            $qb->andWhere('LOWER(CONCAT(u.firstName, \' \', u.lastName)) LIKE :nome')->setParameter('nome', '%'.$nome.'%');
        }
        if ('' !== ($email = mb_strtolower(trim((string) ($f['email'] ?? ''))))) {
            $qb->andWhere('LOWER(u.email) LIKE :email')->setParameter('email', '%'.$email.'%');
        }
        match ((string) ($f['stato'] ?? '')) {
            'attivi' => $qb->andWhere('u.active = true AND u.email NOT LIKE :eliminati'),
            'bloccati' => $qb->andWhere('u.active = false AND u.email NOT LIKE :eliminati'),
            'in_attesa' => $qb->andWhere('u.password = \'\' AND u.googleSubject IS NULL AND u.email NOT LIKE :eliminati'),
            'eliminati' => $qb->andWhere('u.email LIKE :eliminati'),
            default => $qb->andWhere('u.email NOT LIKE :eliminati'),
        };
        match ((string) ($f['team'] ?? '')) {
            'si' => $qb->andWhere('u.superAdmin = true OR u.platformRole IS NOT NULL'),
            'no' => $qb->andWhere('u.superAdmin = false AND u.platformRole IS NULL'),
            default => null,
        };
        match ((string) ($f['dueFattori'] ?? '')) {
            'si' => $qb->andWhere('u.totpSecret IS NOT NULL'),
            'no' => $qb->andWhere('u.totpSecret IS NULL'),
            default => null,
        };
        $tipo = (string) ($f['tipo'] ?? '');
        if (\in_array($tipo, ['privato', 'azienda'], true)) {
            $qb->andWhere('EXISTS (SELECT m1.id FROM '.Membership::class.' m1 JOIN m1.tenant t1 WHERE m1.user = u AND t1.type = :tipo)')->setParameter('tipo', $tipo);
        } elseif ('nessuno' === $tipo) {
            $qb->andWhere('NOT EXISTS (SELECT m1.id FROM '.Membership::class.' m1 WHERE m1.user = u)');
        }
        if ('' !== ($tenant = mb_strtolower(trim((string) ($f['tenant'] ?? ''))))) {
            $qb->andWhere('EXISTS (SELECT m2.id FROM '.Membership::class.' m2 JOIN m2.tenant t2 WHERE m2.user = u AND LOWER(t2.name) LIKE :tenant)')->setParameter('tenant', '%'.$tenant.'%');
        }
        $verso = 'desc' === ($f['verso'] ?? null) ? 'DESC' : 'ASC';
        $campo = match ((string) ($f['ordina'] ?? '')) {
            'nome' => 'u.lastName',
            'ultimoAccesso' => 'u.lastLoginAt',
            'creato' => 'u.createdAt',
            default => 'u.email',
        };

        return $qb->orderBy($campo, $verso)->addOrderBy('u.id', 'ASC');
    }
}
