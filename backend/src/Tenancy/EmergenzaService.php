<?php

namespace App\Tenancy;

use App\Account\EmailVerificata;
use App\Account\Entity\User;
use App\Account\Push\PushNotifier;
use App\Documenti\Entity\Categoria;
use App\Documenti\Repository\CategoriaRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Doctrine\ElaborazioneIsolata;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\NonPiuValido;
use App\Shared\Errore\NonTrovato;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\ContattoEmergenza;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Accesso d'emergenza: invito del contatto fidato, richiesta con attesa
 * annullabile, concessione automatica in sola lettura, revoca.
 * Ogni passo va nell'audit della famiglia; le email non contengono documenti.
 */
final class EmergenzaService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly CategoriaRepository $categorie,
        private readonly AuditLogger $audit,
        private readonly Posta $posta,
        private readonly PushNotifier $push,
        private readonly ElaborazioneIsolata $isolata,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    // ---- Proprietario ----------------------------------------------------------

    /** @param list<string>|null $categorieIds null = tutte le categorie */
    public function invita(Tenant $tenant, User $owner, string $email, ?array $categorieIds, int $giorni): ContattoEmergenza
    {
        $this->richiediProprietario($tenant, $owner);
        EmailVerificata::richiedi($owner);
        if (TenantType::PRIVATO !== $tenant->getType()) {
            throw new Conflitto('L\'accesso d\'emergenza è per le famiglie.');
        }
        $email = User::normalizeEmail($email);
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new DatiNonValidi('email: indirizzo non valido.');
        }
        if ($giorni < 3 || $giorni > 30) {
            throw new DatiNonValidi('giorniAttesa: da 3 a 30 giorni.');
        }
        $esistente = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $esistente && null !== $this->memberships->findOneByUserAndTenant($esistente, $tenant)) {
            throw new Conflitto('Questa persona fa già parte della famiglia.');
        }
        $attivi = array_filter($this->em->getRepository(ContattoEmergenza::class)->findBy(['tenant' => $tenant]), static fn (ContattoEmergenza $c) => \in_array($c->getStato(), [ContattoEmergenza::INVITATO, ContattoEmergenza::ATTIVO], true));
        foreach ($attivi as $c) {
            if ($c->getEmail() === $email) {
                throw new Conflitto('Questa persona è già un contatto d\'emergenza.');
            }
        }
        if (\count($attivi) >= ContattoEmergenza::MAX_PER_FAMIGLIA) {
            throw new Conflitto(sprintf('Al massimo %d contatti d\'emergenza per famiglia.', ContattoEmergenza::MAX_PER_FAMIGLIA));
        }
        $ids = null;
        if (null !== $categorieIds) {
            $ids = [];
            foreach (array_unique($categorieIds) as $id) {
                $cat = \is_string($id) ? $this->categorie->findUsableById($tenant, $id) : null;
                if (!$cat instanceof Categoria) {
                    throw new DatiNonValidi('categorieIds: categoria non valida per questa famiglia.');
                }
                $ids[] = (string) $cat->getId();
            }
            if ([] === $ids) {
                throw new DatiNonValidi('categorieIds: scegli almeno una categoria, oppure tutte.');
            }
        }
        $token = self::token();
        $c = new ContattoEmergenza($tenant, $owner, $email, $ids, $giorni, hash('sha256', $token));
        $this->em->persist($c);
        $this->em->flush();
        $this->log('emergenza.contatto_invitato', $c, $owner, ['giorni' => $giorni, 'categorie' => null === $ids ? 'tutte' : \count($ids)]);
        $this->email($email, sprintf('%s ti indica come contatto d\'emergenza', $owner->getFullName()), 'emergenza_invito', [
            'chi' => $owner->getFullName(), 'famiglia' => $tenant->getName(), 'giorni' => $giorni,
            'url' => $this->url('/emergenza/accetta?token='.$token),
        ]);

        return $c;
    }

    /** @return list<ContattoEmergenza> */
    public function contatti(Tenant $tenant): array
    {
        return $this->em->getRepository(ContattoEmergenza::class)->findBy(['tenant' => $tenant], ['createdAt' => 'DESC']);
    }

    public function revoca(ContattoEmergenza $c, User $owner): void
    {
        $this->richiediProprietario($c->getTenant(), $owner);
        $membership = $c->getMembership();
        $c->revoca();
        if (null !== $membership) {
            $this->em->remove($membership);
        }
        $this->em->flush();
        $this->log('emergenza.contatto_revocato', $c, $owner, ['accesso_tolto' => null !== $membership]);
        if (null !== $c->getContatto()) {
            $this->email($c->getContatto()->getEmail(), 'Non sei più contatto d\'emergenza', 'emergenza_avviso', [
                'messaggio' => sprintf('la famiglia "%s" ti ha tolto dai contatti d\'emergenza%s.', $c->getTenant()->getName(), null !== $membership ? ' e l\'accesso ai documenti è stato chiuso' : ''),
            ]);
        }
    }

    /** Opposizione dall'app: proprietari e membri (non i lettori né il contatto stesso). */
    public function nega(ContattoEmergenza $c, User $chi): void
    {
        $m = $this->memberships->findOneByUserAndTenant($chi, $c->getTenant());
        if (null === $m || MembershipStatus::ACTIVE !== $m->getStatus() || null !== $m->getRole()->getTenant() || !\in_array($m->getRole()->getSlug(), [Role::OWNER, Role::MEMBER], true)) {
            throw new NonConsentito('Solo i proprietari e i membri della famiglia possono opporsi.');
        }
        $this->negaRichiesta($c, $chi);
    }

    /** Opposizione dal link nell'email (pubblica: il token è la credenziale). */
    public function negaDaLink(string $token): ContattoEmergenza
    {
        $c = '' === trim($token) ? null : $this->em->getRepository(ContattoEmergenza::class)->findOneBy(['annullaTokenHash' => hash('sha256', trim($token))]);
        if (!$c instanceof ContattoEmergenza || ContattoEmergenza::IN_ATTESA !== $c->getRichiestaStato()) {
            throw new NonPiuValido('Link non più valido: la richiesta è già stata decisa.');
        }
        $this->negaRichiesta($c, null);

        return $c;
    }

    // ---- Contatto ------------------------------------------------------------------

    /** @return array{famiglia: string, chi: string, email: string} */
    public function anteprimaInvito(string $token): array
    {
        $c = $this->daInvito($token);

        return ['famiglia' => $c->getTenant()->getName(), 'chi' => (string) $c->getConfiguratoDa()?->getFullName(), 'email' => $c->getEmail()];
    }

    public function accetta(string $token, User $user): ContattoEmergenza
    {
        $c = $this->daInvito($token);
        if ($c->getEmail() !== $user->getEmail()) {
            throw new Conflitto('L\'invito è intestato a un\'altra email: accedi con l\'account di quella casella.');
        }
        if (null !== $this->memberships->findOneByUserAndTenant($user, $c->getTenant())) {
            throw new Conflitto('Fai già parte di questa famiglia.');
        }
        // Il link è arrivato a questa casella.
        $user->segnaEmailVerificata();
        $c->accetta($user);
        $this->em->flush();
        $this->log('emergenza.contatto_accettato', $c, $user);
        foreach ($this->memberships->findActiveOwnerUsers($c->getTenant()) as $owner) {
            $this->email($owner->getEmail(), 'Contatto d\'emergenza confermato', 'emergenza_avviso', [
                'messaggio' => sprintf('%s ha accettato di essere contatto d\'emergenza della famiglia "%s".', $user->getFullName(), $c->getTenant()->getName()),
            ]);
        }

        return $c;
    }

    public function rifiuta(string $token, User $user): void
    {
        $c = $this->daInvito($token);
        if ($c->getEmail() !== $user->getEmail()) {
            throw new Conflitto('L\'invito è intestato a un\'altra email.');
        }
        $c->rifiuta();
        $this->em->flush();
        $this->log('emergenza.contatto_rifiutato', $c, $user);
    }

    /** @return list<ContattoEmergenza> famiglie di cui l'utente è contatto */
    public function miei(User $user): array
    {
        return $this->em->getRepository(ContattoEmergenza::class)->findBy(['contatto' => $user, 'stato' => ContattoEmergenza::ATTIVO], ['createdAt' => 'DESC']);
    }

    public function richiedi(ContattoEmergenza $c, User $user, ?string $motivo): void
    {
        if (!$c->getContatto()?->getId()->equals($user->getId())) {
            throw new NonTrovato('Contatto non trovato.');
        }
        if (!$c->puoChiedere()) {
            throw new Conflitto('C\'è già una richiesta in corso o l\'accesso è già stato concesso.');
        }
        $token = self::token();
        $c->richiedi(null !== $motivo && '' !== trim($motivo) ? mb_substr(trim($motivo), 0, 500) : null, hash('sha256', $token));
        $this->em->flush();
        $this->log('emergenza.richiesta', $c, $user, ['eseguibile_il' => $c->getEseguibileIl()?->format(\DATE_ATOM)]);

        // Avviso a proprietari e membri: chi non si oppone entro N giorni lascia passare.
        foreach ($this->famiglia($c->getTenant()) as $u) {
            $this->push->notifica($u, 'CartellaViva', sprintf('%s ha chiesto l\'accesso d\'emergenza', $user->getFullName()), '/famiglia/impostazioni');
            $this->email($u->getEmail(), sprintf('%s ha chiesto l\'accesso d\'emergenza', $user->getFullName()), 'emergenza_richiesta', [
                'chi' => $user->getFullName(), 'famiglia' => $c->getTenant()->getName(), 'motivo' => $c->getMotivo(),
                'quando' => $c->getEseguibileIl(), 'url' => $this->url('/emergenza/annulla?token='.$token), 'app' => $this->url('/famiglia/impostazioni'),
            ]);
        }
    }

    // ---- Pianificatore ---------------------------------------------------------------

    /** Concede gli accessi arrivati a fine attesa senza opposizione (PuliziaPeriodica, ogni ora). */
    public function eseguiDovute(): int
    {
        /** @var list<ContattoEmergenza> $dovute */
        $dovute = $this->em->createQuery('SELECT c FROM '.ContattoEmergenza::class.' c WHERE c.richiestaStato = :s AND c.eseguibileIl <= :ora AND c.stato = :attivo')
            ->setParameter('s', ContattoEmergenza::IN_ATTESA)->setParameter('ora', new \DateTimeImmutable())->setParameter('attivo', ContattoEmergenza::ATTIVO)
            ->getResult();
        $ruolo = $this->roles->getSystemRole(Role::EMERGENZA);

        // Una alla volta: una concessione che fallisce non blocca le altre.
        return $this->isolata->perOgni($dovute, function (ContattoEmergenza $c) use ($ruolo): void {
            $user = $c->getContatto();
            if (null === $user || !$user->isActive() || $c->getTenant()->isInEliminazione()) {
                $c->nega(null);
                $this->em->flush();

                return;
            }
            // Il ruolo è di sistema: dopo un errore del database va ricaricato nell'EntityManager riaperto.
            $ruolo = $this->em->contains($ruolo) ? $ruolo : $this->roles->getSystemRole(Role::EMERGENZA);
            $m = $this->memberships->findOneByUserAndTenant($user, $c->getTenant()) ?? Membership::perEmergenza($user, $c->getTenant(), $ruolo, $c->getCategorie());
            $this->em->persist($m);
            $c->concedi($m);
            $this->em->flush();
            $this->log('emergenza.accesso_concesso', $c, null, ['categorie' => null === $c->getCategorie() ? 'tutte' : \count($c->getCategorie())]);
            $this->push->notifica($user, 'CartellaViva', sprintf('Accesso d\'emergenza concesso: %s', $c->getTenant()->getName()), '/dashboard');
            $this->email($user->getEmail(), 'Accesso d\'emergenza concesso', 'emergenza_avviso', [
                'messaggio' => sprintf('ora puoi consultare in sola lettura i documenti della famiglia "%s" su CartellaViva. Ogni accesso è registrato.', $c->getTenant()->getName()),
            ]);
            foreach ($this->famiglia($c->getTenant()) as $u) {
                if (!$u->getId()->equals($user->getId())) {
                    $this->email($u->getEmail(), 'Accesso d\'emergenza concesso', 'emergenza_avviso', [
                        'messaggio' => sprintf('nessuno si è opposto: %s ora può consultare in sola lettura i documenti della famiglia "%s". Un proprietario può revocare l\'accesso in ogni momento.', $user->getFullName(), $c->getTenant()->getName()),
                    ]);
                }
            }
        }, 'Accesso d\'emergenza');
    }

    // ---- Supporto ---------------------------------------------------------------------

    private function negaRichiesta(ContattoEmergenza $c, ?User $chi): void
    {
        if (ContattoEmergenza::IN_ATTESA !== $c->getRichiestaStato()) {
            throw new Conflitto('Non c\'è nessuna richiesta in attesa.');
        }
        $c->nega($chi);
        $this->em->flush();
        $this->log('emergenza.richiesta_negata', $c, $chi, ['via' => null === $chi ? 'link' : 'app']);
        if (null !== $c->getContatto()) {
            $this->email($c->getContatto()->getEmail(), 'Richiesta d\'accesso d\'emergenza non accolta', 'emergenza_avviso', [
                'messaggio' => sprintf('la famiglia "%s" si è opposta alla tua richiesta di accesso d\'emergenza.', $c->getTenant()->getName()),
            ]);
        }
    }

    /** @return list<User> proprietari e membri attivi (non lettori né accessi d'emergenza) */
    private function famiglia(Tenant $tenant): array
    {
        $out = [];
        foreach ($this->memberships->findForTenant($tenant) as $m) {
            if (MembershipStatus::ACTIVE === $m->getStatus() && $m->getUser()->isActive() && null === $m->getRole()->getTenant() && \in_array($m->getRole()->getSlug(), [Role::OWNER, Role::MEMBER], true)) {
                $out[] = $m->getUser();
            }
        }

        return $out;
    }

    private function richiediProprietario(Tenant $tenant, User $user): void
    {
        foreach ($this->memberships->findActiveOwnerUsers($tenant) as $owner) {
            if ($owner->getId()->equals($user->getId())) {
                return;
            }
        }
        throw new NonConsentito('Solo un proprietario della famiglia gestisce i contatti d\'emergenza.');
    }

    private function daInvito(string $token): ContattoEmergenza
    {
        $c = '' === trim($token) ? null : $this->em->getRepository(ContattoEmergenza::class)->findOneBy(['invitoTokenHash' => hash('sha256', trim($token))]);
        if (!$c instanceof ContattoEmergenza || !$c->isInvitoValido()) {
            throw new NonPiuValido('Invito scaduto o già usato.');
        }

        return $c;
    }

    /** @param array<string, mixed> $context */
    private function log(string $action, ContattoEmergenza $c, ?User $actor, array $context = []): void
    {
        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'contatto_emergenza', resourceId: (string) $c->getId(), tenantId: $c->getTenant()->getId(), context: $context, actor: $actor);
    }

    /** @param array<string, mixed> $context */
    private function email(string $to, string $subject, string $template, array $context): void
    {
        $this->posta->invia($to, $subject, 'emails/'.$template.'.html.twig', $context);
    }

    private function url(string $path): string
    {
        return rtrim($this->frontendUrl, '/').$path;
    }

    private static function token(): string
    {
        return TokenCasuale::nuovo();
    }
}
