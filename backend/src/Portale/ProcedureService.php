<?php

namespace App\Portale;

use App\Account\Entity\RefreshToken;
use App\Account\Entity\TwoFactorBackupCode;
use App\Account\Entity\User;
use App\Portale\Entity\ProceduraAccesso;
use App\Shared\Audit\AuditLogger;
use App\Shared\Doctrine\ElaborazioneIsolata;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonPiuValido;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Recupero della 2FA e nuovo proprietario per le famiglie rimaste senza.
 * Ogni passo va nell'audit globale e in quello delle famiglie coinvolte.
 * Nessuna email contiene dati dei documenti.
 */
final class ProcedureService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly Posta $posta,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly ElaborazioneIsolata $isolata,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    // ---- Recupero della 2FA -------------------------------------------------

    /**
     * Richiesta pubblica dalla schermata del codice. La risposta è sempre la
     * stessa: non si rivela se l'email esiste o se ha la 2FA.
     */
    public function richiediRecupero(string $email, ?string $motivo): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => User::normalizeEmail($email)]);
        if (!$user instanceof User || !$user->isActive() || !$user->isTwoFactorEnabled() || null !== $this->aperta($user, ProceduraAccesso::RECUPERO_2FA)) {
            return;
        }
        $token = self::nuovoToken();
        $p = ProceduraAccesso::recupero2fa($user, null !== $motivo && '' !== trim($motivo) ? mb_substr(trim($motivo), 0, 500) : null, self::hash($token));
        $this->em->persist($p);
        $this->em->flush();
        $this->log('procedura.recupero_richiesto', $p);
        $this->email($user, 'Conferma la richiesta di recupero dell\'accesso', 'conferma', ['url' => $this->url('/auth/recupero/conferma?token='.$token)]);
    }

    public function confermaRecupero(string $token): ProceduraAccesso
    {
        $p = $this->daToken('tokenConfermaHash', $token);
        if (!$p->confermaEmail()) {
            throw new NonPiuValido('Link scaduto o già usato: invia una nuova richiesta.');
        }
        $this->em->flush();
        $this->log('procedura.recupero_confermato', $p);

        return $p;
    }

    /**
     * Esito della verifica d'identità fatta dal supporto (videochiamata con
     * documento). Verificata: partono le 72 ore con avviso.
     */
    public function verificaIdentita(ProceduraAccesso $p, User $supporto, bool $verificata, ?string $documento, ?string $come, ?string $nota): void
    {
        if (ProceduraAccesso::RECUPERO_2FA !== $p->getTipo() || ProceduraAccesso::IDENTITA_DA_VERIFICARE !== $p->getStato()) {
            throw new Conflitto('Questa procedura non è in attesa di verifica dell\'identità.');
        }
        if ($p->getUser()->getId()->equals($supporto->getId())) {
            throw new Conflitto('Non puoi verificare la tua stessa identità.');
        }
        if (!$verificata) {
            $p->chiudi(ProceduraAccesso::RESPINTA, $supporto, self::testo($nota, 500));
            $this->em->flush();
            $this->log('procedura.identita_respinta', $p, $supporto);
            $this->email($p->getUser(), 'Richiesta di recupero non accolta', 'respinta', ['nota' => $p->getNota()]);

            return;
        }
        $documento = self::testo($documento, 120) ?? throw new DatiNonValidi('documento: indica tipo e ultime cifre del documento controllato.');
        $come = self::testo($come, 120) ?? throw new DatiNonValidi('come: indica come hai verificato l\'identità.');
        $token = self::nuovoToken();
        $p->identitaVerificata($supporto, $documento, $come, self::testo($nota, 500), self::hash($token));
        $this->em->flush();
        $this->log('procedura.identita_verificata', $p, $supporto);
        $this->avvisaAttesa($p, $token);
    }

    // ---- Nuovo proprietario -------------------------------------------------

    /** Famiglie senza alcun proprietario attivo (con un account attivo). */
    public function famiglieSenzaProprietario(): array
    {
        return $this->em->createQuery(
            'SELECT t FROM '.Tenant::class.' t WHERE t.type = :privato AND t.eliminazioneProgrammataIl IS NULL AND NOT EXISTS (
                SELECT m.id FROM '.Membership::class.' m JOIN m.role r JOIN m.user u
                WHERE m.tenant = t AND m.status = :attiva AND r.slug = :owner AND r.tenant IS NULL AND u.active = true
            ) ORDER BY t.name ASC'
        )
            ->setParameter('privato', TenantType::PRIVATO)
            ->setParameter('attiva', MembershipStatus::ACTIVE)
            ->setParameter('owner', Role::OWNER)
            ->getResult();
    }

    public function avviaNuovoProprietario(Tenant $famiglia, Membership $membership, User $supporto, ?string $documento, ?string $come, ?string $nota): ProceduraAccesso
    {
        if (!\in_array($famiglia, $this->famiglieSenzaProprietario(), true)) {
            throw new Conflitto('La famiglia ha già un proprietario attivo: la procedura non serve.');
        }
        if (!$membership->getTenant()->getId()->equals($famiglia->getId()) || MembershipStatus::ACTIVE !== $membership->getStatus() || !$membership->getUser()->isActive()) {
            throw new DatiNonValidi('membershipId: scegli un membro attivo della famiglia.');
        }
        if (null !== $this->aperta($membership->getUser(), ProceduraAccesso::NUOVO_PROPRIETARIO, $famiglia)) {
            throw new Conflitto('C\'è già una procedura in corso per questo membro.');
        }
        $documento = self::testo($documento, 120) ?? throw new DatiNonValidi('documento: indica tipo e ultime cifre del documento controllato.');
        $come = self::testo($come, 120) ?? throw new DatiNonValidi('come: indica come hai verificato l\'identità.');
        $token = self::nuovoToken();
        $p = ProceduraAccesso::nuovoProprietario($membership->getUser(), $famiglia, $supporto, $documento, $come, self::testo($nota, 500), self::hash($token));
        $this->em->persist($p);
        $this->em->flush();
        $this->log('procedura.nuovo_proprietario_avviato', $p, $supporto);
        $this->avvisaAttesa($p, $token);

        return $p;
    }

    // ---- Annullamento ed esecuzione -----------------------------------------

    /** "Non sono stato io" / "non sono d'accordo" dal link dell'avviso (pubblico). */
    public function annullaDaLink(string $token): ProceduraAccesso
    {
        $p = $this->daToken('tokenAnnullaHash', $token);
        if (ProceduraAccesso::IN_ATTESA !== $p->getStato()) {
            throw new NonPiuValido('La procedura è già conclusa.');
        }
        $p->chiudi(ProceduraAccesso::ANNULLATA, null, 'Annullata dal link dell\'avviso.');
        $this->em->flush();
        // Allerta: qualcuno potrebbe aver tentato di prendersi l'account o la famiglia.
        $this->audit->log('procedura.annullata_da_avviso', AuditOutcome::DENIED, resourceType: 'procedura_accesso', resourceId: (string) $p->getId(), tenantId: $p->getTenant()?->getId(), context: ['tipo' => $p->getTipo()]);
        $this->logger->warning('Procedura di accesso {id} annullata dal link di avviso', ['id' => (string) $p->getId()]);

        return $p;
    }

    public function annullaDalSupporto(ProceduraAccesso $p, User $supporto, ?string $nota): void
    {
        if (!$p->isAperta()) {
            throw new Conflitto('La procedura è già conclusa.');
        }
        $p->chiudi(ProceduraAccesso::ANNULLATA, $supporto, self::testo($nota, 500));
        $this->em->flush();
        $this->log('procedura.annullata', $p, $supporto);
    }

    /** Job orario: esegue le procedure la cui attesa è finita. */
    public function eseguiDovute(): int
    {
        $dovute = $this->em->createQuery('SELECT p FROM '.ProceduraAccesso::class.' p WHERE p.stato = :attesa AND p.eseguibileIl <= :ora')
            ->setParameter('attesa', ProceduraAccesso::IN_ATTESA)
            ->setParameter('ora', new \DateTimeImmutable())
            ->getResult();
        return $this->isolata->perOgni($dovute, function (ProceduraAccesso $p): void {
            if (ProceduraAccesso::RECUPERO_2FA === $p->getTipo()) {
                $this->eseguiRecupero($p);
            } else {
                $this->eseguiNuovoProprietario($p);
            }
        }, 'Procedura di accesso');
    }

    private function eseguiRecupero(ProceduraAccesso $p): void
    {
        $user = $p->getUser();
        $user->resetTwoFactorPerRecupero();
        $this->em->createQuery('DELETE FROM '.TwoFactorBackupCode::class.' c WHERE c.user = :u')->setParameter('u', $user->getId(), 'uuid')->execute();
        // Sessioni chiuse ovunque: tutti i refresh token dell'account.
        $this->em->createQuery('DELETE FROM '.RefreshToken::class.' r WHERE r.username = :e')->setParameter('e', $user->getEmail())->execute();
        $p->chiudi(ProceduraAccesso::COMPLETATA);
        $this->em->flush();
        $this->log('procedura.recupero_completato', $p);
        $this->email($user, 'Accesso sbloccato: riattiva la verifica in due passaggi', 'completata_recupero', ['url' => $this->url('/auth/login')]);
    }

    private function eseguiNuovoProprietario(ProceduraAccesso $p): void
    {
        $famiglia = $p->getTenant();
        $membership = null !== $famiglia ? $this->memberships->findOneByUserAndTenant($p->getUser(), $famiglia) : null;
        if (null === $membership || MembershipStatus::ACTIVE !== $membership->getStatus() || !$p->getUser()->isActive()) {
            $p->chiudi(ProceduraAccesso::ANNULLATA, null, 'Il membro non fa più parte della famiglia.');
            $this->em->flush();
            $this->log('procedura.annullata', $p);

            return;
        }
        $membership->changeRole($this->roles->getSystemRole(Role::OWNER));
        $p->chiudi(ProceduraAccesso::COMPLETATA);
        $this->em->flush();
        $this->log('procedura.nuovo_proprietario_completato', $p);
        $this->email($p->getUser(), sprintf('Ora sei proprietario di "%s"', $famiglia->getName()), 'completata_proprietario', ['famiglia' => $famiglia->getName(), 'url' => $this->url('/famiglia/impostazioni')]);
    }

    // ---- Helpers ------------------------------------------------------------

    /** Avviso delle 72 ore: all'utente e ai proprietari (recupero) o a tutti i membri (nuovo proprietario). */
    private function avvisaAttesa(ProceduraAccesso $p, string $tokenAnnulla): void
    {
        $destinatari = [$p->getUser()->getEmail() => $p->getUser()];
        $famiglie = null !== $p->getTenant() ? [$p->getTenant()] : array_map(static fn (Membership $m) => $m->getTenant(), $this->memberships->findActiveForUser($p->getUser()));
        foreach ($famiglie as $famiglia) {
            foreach ($this->memberships->findForTenant($famiglia) as $m) {
                $tutti = ProceduraAccesso::NUOVO_PROPRIETARIO === $p->getTipo();
                if (MembershipStatus::ACTIVE === $m->getStatus() && ($tutti || $m->getRole()->isOwnerRole())) {
                    $destinatari[$m->getUser()->getEmail()] = $m->getUser();
                }
            }
        }
        foreach ($destinatari as $u) {
            $this->email($u, ProceduraAccesso::RECUPERO_2FA === $p->getTipo() ? 'Avviso: sblocco dell\'accesso tra 72 ore' : 'Avviso: nuovo proprietario della famiglia tra 72 ore', 'attesa', [
                'tipo' => $p->getTipo(),
                'interessato' => $p->getUser()->getFullName(),
                'famiglia' => $p->getTenant()?->getName(),
                'quando' => $p->getEseguibileIl(),
                'url' => $this->url('/procedure/annulla?token='.$tokenAnnulla),
            ]);
        }
    }

    private function aperta(User $user, string $tipo, ?Tenant $tenant = null): ?ProceduraAccesso
    {
        foreach ($this->em->getRepository(ProceduraAccesso::class)->findBy(['user' => $user, 'tipo' => $tipo]) as $p) {
            if ($p->isAperta() && (null === $tenant || $p->getTenant()?->getId()->equals($tenant->getId()))) {
                return $p;
            }
        }

        return null;
    }

    private function daToken(string $campo, string $token): ProceduraAccesso
    {
        $p = '' !== $token ? $this->em->getRepository(ProceduraAccesso::class)->findOneBy([$campo => self::hash($token)]) : null;

        return $p instanceof ProceduraAccesso ? $p : throw new NonPiuValido('Link non valido o già usato.');
    }

    private function log(string $action, ProceduraAccesso $p, ?User $actor = null): void
    {
        $context = ['tipo' => $p->getTipo(), 'utente' => (string) $p->getUser()->getId(), 'stato' => $p->getStato()];
        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'procedura_accesso', resourceId: (string) $p->getId(), context: $context, actor: $actor);
        // Anche nel registro delle famiglie coinvolte.
        $famiglie = null !== $p->getTenant() ? [$p->getTenant()] : array_map(static fn (Membership $m) => $m->getTenant(), $this->memberships->findActiveForUser($p->getUser()));
        foreach ($famiglie as $t) {
            $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'procedura_accesso', resourceId: (string) $p->getId(), tenantId: $t->getId(), context: $context, actor: $actor);
        }
    }

    /** @param array<string, mixed> $context */
    private function email(User $to, string $subject, string $caso, array $context): void
    {
        $this->posta->invia($to->getEmail(), $subject, 'emails/procedura_accesso.html.twig', $context + ['nome' => $to->getFirstName(), 'caso' => $caso]);
    }

    private function url(string $path): string
    {
        return rtrim($this->frontendUrl, '/').$path;
    }

    private static function nuovoToken(): string
    {
        return TokenCasuale::nuovo();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', 'procedura|'.$token);
    }

    private static function testo(?string $value, int $max): ?string
    {
        $value = null !== $value ? trim($value) : '';

        return '' === $value ? null : mb_substr($value, 0, $max);
    }
}
