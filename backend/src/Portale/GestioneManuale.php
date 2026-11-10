<?php

namespace App\Portale;

use App\Account\AccountService;
use App\Account\Entity\DispositivoPush;
use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use App\Ente\CollegamentoService;
use App\Ente\EnteDatiValidator;
use App\Ente\EnteRegistrazione;
use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\EnteProfilo;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\Referente;
use App\Ente\Enum\EnteStato;
use App\Shared\Audit\AuditLogger;
use App\Shared\Doctrine\PerId;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\ErroreDominio;
use App\Shared\Mail\Posta;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\MembershipManager;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Repository\RoleRepository;
use App\Tenancy\RoleGrantPolicy;
use App\Tenancy\TenantProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Gestione manuale dal portale (solo superadmin): famiglie, enti e membri
 * creati a mano per chi chiede aiuto.
 *
 * - Un tenant nasce sempre con il suo proprietario (nuovo o esistente).
 * - Chi non ha un account lo riceve senza password, con il link "Attiva il
 *   tuo account" (7 giorni); chi ce l'ha riceve un avviso.
 * - L'ente creato qui è già verificato, con la nota obbligatoria su come.
 * - Gli account del team del portale non diventano mai membri da qui: sarebbe
 *   un modo per leggere i documenti di una famiglia.
 * - Ogni modifica è nel registro e i proprietari ne ricevono avviso.
 * - Il collegamento ente-famiglia passa sempre dall'invito email del referente.
 */
final class GestioneManuale
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly MembershipRepository $memberships,
        private readonly TenantProvisioner $provisioner,
        private readonly EnteDatiValidator $enteValidator,
        private readonly MembershipManager $membershipManager,
        private readonly RoleGrantPolicy $grantPolicy,
        private readonly AccountService $account,
        private readonly CollegamentoService $collegamenti,
        private readonly ValidatorInterface $validator,
        private readonly Posta $posta,
        private readonly AuditLogger $audit,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * @param array<string, mixed>       $proprietario {email, nome?, cognome?}
     * @param list<array<string, mixed>> $membri       [{email, nome?, cognome?, ruolo}]
     */
    public function creaFamiglia(string $nome, array $proprietario, array $membri, User $admin): Tenant
    {
        $nome = trim($nome);
        if ('' === $nome || mb_strlen($nome) > 150) {
            throw new DatiNonValidi('nome: obbligatorio (massimo 150 caratteri).');
        }

        return $this->crea(TenantType::PRIVATO, $nome, $proprietario, $membri, $admin, null);
    }

    /**
     * @param array<string, mixed>       $dati         come la registrazione dell'ente
     * @param array<string, mixed>       $proprietario {email, nome?, cognome?}
     * @param list<array<string, mixed>> $staff        [{email, nome?, cognome?, ruolo?}]
     */
    public function creaEnte(array $dati, string $notaVerifica, array $proprietario, array $staff, User $admin): Tenant
    {
        $dati = $this->enteValidator->validate($dati);
        $notaVerifica = trim($notaVerifica);
        if (mb_strlen($notaVerifica) < 10) {
            throw new DatiNonValidi('notaVerifica: scrivi come avete verificato l\'ente (es. contratto, telefonata alla segreteria).');
        }
        $esistente = $this->em->getRepository(EnteProfilo::class)->findOneBy(['codiceFiscale' => $dati['codiceFiscale']]);
        if (null !== $esistente && EnteStato::RESPINTO !== $esistente->getStato()) {
            throw new Conflitto(sprintf('Esiste già un ente con questo codice fiscale: "%s".', $esistente->getTenant()->getName()));
        }
        $staff = array_map(static fn (array $s) => $s + ['ruolo' => Role::STAFF], $staff);

        return $this->crea(TenantType::AZIENDA, $dati['denominazione'], $proprietario, $staff, $admin, function (Tenant $tenant) use ($dati, $notaVerifica, $admin): void {
            $profilo = new EnteProfilo($tenant, $dati['tipo'], $dati['codiceFiscale'], $dati['emailIstituzionale']);
            $profilo->aggiornaDati($dati['codiceFiscale'], $dati['codiceMeccanografico'], $dati['numeroRunts'], $dati['emailIstituzionale'], $dati['sedeLegale']);
            $profilo->decidi(EnteStato::VERIFICATO, $admin, $notaVerifica, ['registro' => true, 'telefonata' => false, 'portale' => true]);
            $this->em->persist($profilo);
            $this->em->persist(new AnnoEnte($tenant, EnteRegistrazione::annoCorrente(new \DateTimeImmutable())));
        });
    }

    /** @param array<string, mixed> $persona {email, nome?, cognome?} */
    public function aggiungiMembro(Tenant $tenant, array $persona, string $ruolo, User $admin): Membership
    {
        $this->assertModificabile($tenant);
        $spec = $this->persona($persona);
        $role = $this->ruolo($tenant, $ruolo);
        if (null !== $spec['user'] && null !== $this->memberships->findOneByUserAndTenant($spec['user'], $tenant)) {
            throw new Conflitto('Questa persona fa già parte del tenant.');
        }
        $this->grantPolicy->assertCanGrant($admin, $tenant, $role);

        [$membership, $nuovo] = $this->transazione(function () use ($tenant, $spec, $role, $admin): array {
            $user = $this->utente($spec);

            $m = new Membership($user, $tenant, $role, $admin);
            $this->em->persist($m);
            $this->em->flush();

            return [$m, null === $spec['user']];
        });

        $this->audit->log('portale.membro_aggiunto', AuditOutcome::SUCCESS, resourceType: 'membership', resourceId: (string) $membership->getId(), tenantId: $tenant->getId(), context: ['email' => $membership->getUser()->getEmail(), 'ruolo' => $role->getSlug()], actor: $admin);
        $this->benvenuto($membership, $nuovo);
        $this->avvisaProprietari($tenant, sprintf('il team di CartellaViva ha aggiunto %s a "%s" con il ruolo "%s".', $membership->getUser()->getFullName(), $tenant->getName(), $role->getName()), [$membership->getUser()]);

        return $membership;
    }

    public function cambiaRuolo(Membership $membership, string $ruolo, User $admin): void
    {
        $tenant = $membership->getTenant();
        $this->assertModificabile($tenant);
        $role = $this->ruolo($tenant, $ruolo);
        $prima = $membership->getRole()->getName();
        $this->membershipManager->changeRole($admin, $membership, $role);
        if ($prima !== $role->getName()) {
            $this->avvisa($membership->getUser(), sprintf('il team di CartellaViva ha cambiato il tuo ruolo in "%s" da "%s" a "%s".', $tenant->getName(), $prima, $role->getName()));
            $this->avvisaProprietari($tenant, sprintf('il team di CartellaViva ha cambiato il ruolo di %s in "%s" da "%s" a "%s".', $membership->getUser()->getFullName(), $tenant->getName(), $prima, $role->getName()), [$membership->getUser()]);
        }
    }

    public function cambiaStato(Membership $membership, bool $attiva, User $admin): void
    {
        $this->assertModificabile($membership->getTenant());
        $prima = $membership->getStatus();
        $this->membershipManager->changeStatus($admin, $membership, $attiva ? MembershipStatus::ACTIVE : MembershipStatus::SUSPENDED);
        if ($prima !== $membership->getStatus()) {
            $testo = $attiva ? 'ha riattivato' : 'ha sospeso';
            $this->avvisaProprietari($membership->getTenant(), sprintf('il team di CartellaViva %s l\'accesso di %s a "%s".', $testo, $membership->getUser()->getFullName(), $membership->getTenant()->getName()), [$membership->getUser()]);
        }
    }

    public function rimuovi(Membership $membership, User $admin): void
    {
        $tenant = $membership->getTenant();
        $user = $membership->getUser();
        $this->membershipManager->remove($admin, $membership);
        $this->avvisa($user, sprintf('il team di CartellaViva ti ha tolto l\'accesso a "%s".', $tenant->getName()));
        $this->avvisaProprietari($tenant, sprintf('il team di CartellaViva ha tolto l\'accesso a "%s" a %s.', $tenant->getName(), $user->getFullName()), [$user]);
    }

    public function reinviaAttivazione(User $user, User $admin): void
    {
        $dove = array_map(static fn (Membership $m) => $m->getTenant()->getName(), $this->em->getRepository(Membership::class)->findBy(['user' => $user]));
        $this->account->inviaAttivazione($user, [] !== $dove ? implode(', ', $dove) : null);
        $this->audit->log('portale.attivazione_reinviata', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $admin);
    }

    /**
     * Inserisce l'iscritto e il referente nell'ente e manda l'invito al
     * collegamento: la famiglia accetta (e sceglie la persona) dal link.
     *
     * @param array<string, mixed> $iscritto  {nome, cognome}
     * @param array<string, mixed> $referente {nome, cognome, email, relazione?}
     */
    public function invitaFamiglia(Tenant $ente, array $iscritto, array $referente, ?string $gruppoId, User $admin): Referente
    {
        $this->assertModificabile($ente);
        $profilo = $this->em->getRepository(EnteProfilo::class)->findOneBy(['tenant' => $ente]);
        if (!$profilo instanceof EnteProfilo || !$profilo->isVerificato()) {
            throw new Conflitto('L\'ente deve essere verificato per invitare le famiglie.');
        }
        $nomeIscritto = $this->testo($iscritto, 'nome', 'iscritto.nome');
        $cognomeIscritto = $this->testo($iscritto, 'cognome', 'iscritto.cognome');
        $email = $this->email((string) ($referente['email'] ?? ''), 'referente.email');
        $relazione = trim((string) ($referente['relazione'] ?? '')) ?: null;
        $gruppo = null;
        if (null !== $gruppoId && '' !== $gruppoId) {
            $gruppo = PerId::trova($this->em, Gruppo::class, $gruppoId);
            if (!$gruppo instanceof Gruppo || !$gruppo->getTenant()->getId()->equals($ente->getId())) {
                throw new DatiNonValidi('gruppoId: gruppo non trovato nell\'ente.');
            }
        }

        $i = new Iscritto($ente, $nomeIscritto, $cognomeIscritto);
        $r = new Referente($i, $this->testo($referente, 'nome', 'referente.nome'), $this->testo($referente, 'cognome', 'referente.cognome'), $email, null !== $relazione ? mb_substr($relazione, 0, 50) : null);
        if (null !== $gruppo) {
            $i->iscriviA($gruppo);
        }
        $this->em->persist($i);
        $this->em->flush();
        $this->audit->log('portale.famiglia_invitata', AuditOutcome::SUCCESS, resourceType: 'ente_referente', resourceId: (string) $r->getId(), tenantId: $ente->getId(), actor: $admin);
        $this->collegamenti->invita($ente, [$r], $admin);

        return $r;
    }

    // ---- Modifica ed eliminazione ----------------------------------------

    /**
     * Nome, cognome ed email. L'email cambia subito: sessioni chiuse, avviso al
     * vecchio indirizzo, il nuovo va confermato (o riceve l'attivazione).
     */
    public function modificaUtente(User $user, string $nome, string $cognome, string $email, User $admin): void
    {
        $this->assertUtenteGestibile($user, $admin);
        $nome = $this->testo(['v' => $nome], 'v', 'nome');
        $cognome = $this->testo(['v' => $cognome], 'v', 'cognome');
        $email = $this->email($email, 'email');
        $vecchia = $user->getEmail();
        $cambiaEmail = $email !== $vecchia;
        if ($cambiaEmail && null !== $this->users->findOneByEmail($email)) {
            throw new Conflitto('email: questo indirizzo è già usato da un altro account.');
        }

        $user->rename($nome, $cognome);
        if ($cambiaEmail) {
            // Prima del cambio: i refresh token sono legati all'indirizzo.
            $this->account->chiudiSessioni($user);
            $user->impostaEmailDaConfermare($email);
        }
        $this->em->flush();
        $this->audit->log('portale.utente_modificato', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(),
            context: ['email_cambiata' => $cambiaEmail, 'da' => $cambiaEmail ? AccountService::maschera($vecchia) : null], actor: $admin);

        if (!$cambiaEmail) {
            return;
        }
        $this->inviaA($vecchia, $user->getFirstName(), sprintf('il team di CartellaViva ha cambiato l\'indirizzo email del tuo account in %s. Le sessioni aperte sono state chiuse.', AccountService::maschera($email)));
        if (AccountService::inAttesaDiAttivazione($user)) {
            $this->account->inviaAttivazione($user);
        } else {
            $this->account->inviaVerifica($user);
        }
    }

    /**
     * "Elimina": l'utente esce da famiglie ed enti (i suoi dati passano a un
     * proprietario) e l'account viene anonimizzato e disattivato. Non si può
     * se è l'unico proprietario di qualcosa: prima va nominato un altro.
     */
    public function eliminaUtente(User $user, User $admin): void
    {
        $this->assertUtenteGestibile($user, $admin);
        $appartenenze = $this->em->getRepository(Membership::class)->findBy(['user' => $user]);
        $bloccanti = [];
        foreach ($appartenenze as $m) {
            if (MembershipStatus::ACTIVE !== $m->getStatus() || !$m->getRole()->isOwnerRole()) {
                continue;
            }
            $altri = array_filter($this->memberships->findActiveOwnerUsers($m->getTenant()), static fn (User $u) => !$u->getId()->equals($user->getId()));
            if ([] === $altri) {
                $bloccanti[] = $m->getTenant()->getName();
            }
        }
        if ([] !== $bloccanti) {
            throw new Conflitto(sprintf('È l\'unico proprietario di: %s. Nomina prima un altro proprietario, oppure elimina la famiglia o l\'ente.', implode(', ', $bloccanti)));
        }

        $nome = $user->getFullName();
        $email = $user->getEmail();
        $this->inviaA($email, $user->getFirstName(), 'il tuo account CartellaViva è stato eliminato dal team di CartellaViva: non potrai più accedere. I documenti che avevi caricato restano alle famiglie a cui appartengono.');
        foreach ($appartenenze as $m) {
            $tenant = $m->getTenant();
            $this->membershipManager->remove($admin, $m);
            $this->avvisaProprietari($tenant, sprintf('il team di CartellaViva ha eliminato l\'account di %s, che non fa più parte di "%s". I suoi documenti sono passati a un proprietario.', $nome, $tenant->getName()));
        }

        // Prima dell'anonimizzazione: i refresh token sono legati all'indirizzo.
        $this->account->chiudiSessioni($user);
        $this->em->createQuery('DELETE FROM '.DispositivoPush::class.' d WHERE d.user = :u')->setParameter('u', $user->getId(), 'uuid')->execute();
        $anagrafica = $user->getAnagrafica();
        $user->setAnagrafica(null);
        if (null !== $anagrafica && 0 === (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.MembroFamiglia::class.' m WHERE m.anagrafica = :a')->setParameter('a', $anagrafica)->getSingleScalarResult()) {
            $this->em->remove($anagrafica);
        }
        $user->anonimizza();
        $this->em->flush();
        $this->audit->log('portale.utente_eliminato', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['email' => AccountService::maschera($email), 'appartenenze' => \count($appartenenze)], actor: $admin);
    }

    /**
     * Famiglia: il nome. Ente: anche i dati identificativi; se cambiano resta
     * verificato (la modifica la fa il portale), con la nota nel registro.
     *
     * @param array<string, mixed> $dati
     */
    public function modificaTenant(Tenant $tenant, array $dati, User $admin): void
    {
        $this->assertModificabile($tenant);
        $profilo = $this->em->getRepository(EnteProfilo::class)->findOneBy(['tenant' => $tenant]);
        if (!$profilo instanceof EnteProfilo) {
            $nome = trim((string) ($dati['nome'] ?? ''));
            if ('' === $nome || mb_strlen($nome) > 150) {
                throw new DatiNonValidi('nome: obbligatorio (massimo 150 caratteri).');
            }
            $tenant->rename($nome);
        } else {
            $valid = $this->enteValidator->validate($dati + ['tipo' => $profilo->getTipo()->value]);
            if ($valid['tipo'] !== $profilo->getTipo()) {
                throw new DatiNonValidi('tipo: non si cambia dopo la registrazione.');
            }
            $doppione = $this->em->getRepository(EnteProfilo::class)->findOneBy(['codiceFiscale' => $valid['codiceFiscale']]);
            if (null !== $doppione && $doppione !== $profilo && EnteStato::RESPINTO !== $doppione->getStato()) {
                throw new Conflitto(sprintf('Esiste già un ente con questo codice fiscale: "%s".', $doppione->getTenant()->getName()));
            }
            $verificato = $profilo->isVerificato();
            [$nota, $controlli] = [$profilo->getNotaPortale(), $profilo->getControlli()];
            $tenant->rename($valid['denominazione']);
            if ($profilo->aggiornaDati($valid['codiceFiscale'], $valid['codiceMeccanografico'], $valid['numeroRunts'], $valid['emailIstituzionale'], $valid['sedeLegale']) && $verificato) {
                $profilo->decidi(EnteStato::VERIFICATO, $admin, $nota, ['portale' => true] + $controlli);
            }
        }
        $this->em->flush();
        $this->audit->log('portale.tenant_modificato', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), actor: $admin);
        $this->avvisaProprietari($tenant, sprintf('il team di CartellaViva ha aggiornato i dati di "%s".', $tenant->getName()));
    }

    /**
     * Operazioni di gruppo sugli utenti: blocca, sblocca, attivazione.
     *
     * @param list<string> $ids
     *
     * @return array{fatti: int, saltati: list<array{id: string, email: ?string, motivo: string}>}
     */
    public function bulkUtenti(string $azione, array $ids, User $admin): array
    {
        if (!\in_array($azione, ['blocca', 'sblocca', 'attivazione'], true)) {
            throw new DatiNonValidi('azione: blocca, sblocca o attivazione.');
        }
        if ([] === $ids || \count($ids) > 200) {
            throw new DatiNonValidi('ids: da 1 a 200 utenti.');
        }
        $fatti = 0;
        $saltati = [];
        foreach (array_unique($ids) as $id) {
            $user = PerId::trova($this->em, User::class, $id);
            try {
                if (!$user instanceof User) {
                    throw new DatiNonValidi('utente non trovato');
                }
                match ($azione) {
                    'blocca' => $this->blocca($user, false, $admin),
                    'sblocca' => $this->blocca($user, true, $admin),
                    'attivazione' => $this->reinviaAttivazione($user, $admin),
                };
                ++$fatti;
            } catch (ErroreDominio $e) {
                $saltati[] = ['id' => (string) $id, 'email' => $user?->getEmail(), 'motivo' => $e->getMessage()];
            }
        }

        return ['fatti' => $fatti, 'saltati' => $saltati];
    }

    private function blocca(User $user, bool $attivo, User $admin): void
    {
        $this->assertUtenteGestibile($user, $admin);
        if ($user->isActive() === $attivo) {
            throw new Conflitto($attivo ? 'già attivo' : 'già bloccato');
        }
        $user->setActive($attivo);
        if (!$attivo) {
            $this->account->chiudiSessioni($user);
        }
        $this->em->flush();
        $this->audit->log($attivo ? 'portale.utente_sbloccato' : 'portale.utente_bloccato', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['motivo' => 'operazione di gruppo'], actor: $admin);
    }

    /** Né sé stessi, né il superadmin, né account già eliminati. */
    private function assertUtenteGestibile(User $user, User $admin): void
    {
        if ($user->getId()->equals($admin->getId())) {
            throw new Conflitto('Il tuo account si gestisce dal tuo profilo, non da qui.');
        }
        if ($user->isSuperAdmin()) {
            throw new Conflitto('Gli account superadmin si gestiscono dalla console del server.');
        }
        if ($user->isEliminato()) {
            throw new Conflitto('Account eliminato.');
        }
    }

    /** @return list<array{id: string, nome: string}> gruppi dell'anno in corso */
    public function gruppi(Tenant $ente): array
    {
        $anno = $this->em->getRepository(AnnoEnte::class)->corrente($ente);
        if (!$anno instanceof AnnoEnte) {
            return [];
        }

        return array_map(static fn (Gruppo $g) => ['id' => (string) $g->getId(), 'nome' => $g->getNome()],
            $this->em->getRepository(Gruppo::class)->findBy(['anno' => $anno], ['nome' => 'ASC']));
    }

    // ---- Interni ---------------------------------------------------------

    /**
     * @param array<string, mixed>       $proprietario
     * @param list<array<string, mixed>> $membri
     */
    private function crea(TenantType $tipo, string $nome, array $proprietario, array $membri, User $admin, ?callable $dopo): Tenant
    {
        // Tutto validato prima di scrivere: niente tenant a metà.
        $owner = $this->persona($proprietario, 'proprietario');
        $altri = [];
        $email = [$owner['email']];
        foreach (array_values($membri) as $n => $m) {
            if (!\is_array($m)) {
                throw new DatiNonValidi(sprintf('membri[%d]: dati non validi.', $n));
            }
            $spec = $this->persona($m, sprintf('membri[%d]', $n));
            if (\in_array($spec['email'], $email, true)) {
                throw new DatiNonValidi(sprintf('membri[%d]: %s è già nell\'elenco.', $n, $spec['email']));
            }
            $email[] = $spec['email'];
            $slug = (string) ($m['ruolo'] ?? Role::MEMBER);
            $role = $this->roles->findSystemRole($slug);
            if (!$role instanceof Role || Role::EMERGENZA === $slug || (null !== $role->getTenantType() && $role->getTenantType() !== $tipo)) {
                throw new DatiNonValidi(sprintf('membri[%d].ruolo: ruolo non valido per questo tipo.', $n));
            }
            $altri[] = [$spec, $role];
        }

        [$tenant, $create] = $this->transazione(function () use ($tipo, $nome, $owner, $altri, $admin, $dopo): array {
            $create = [];
            $membership = $this->provisioner->createWithOwner($this->utente($owner), $nome, $tipo);
            $tenant = $membership->getTenant();
            $create[] = [$membership, null === $owner['user']];
            foreach ($altri as [$spec, $role]) {
                $m = new Membership($this->utente($spec), $tenant, $role, $admin);
                $this->em->persist($m);
                $create[] = [$m, null === $spec['user']];
            }
            if (null !== $dopo) {
                $dopo($tenant);
            }
            $this->em->flush();

            return [$tenant, $create];
        });

        $this->audit->log(TenantType::AZIENDA === $tipo ? 'portale.ente_creato' : 'portale.famiglia_creata', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), context: ['membri' => \count($create)], actor: $admin);
        foreach ($create as [$m, $nuovo]) {
            $this->benvenuto($m, $nuovo);
        }

        return $tenant;
    }

    /**
     * Valida i dati di una persona senza scrivere nulla.
     *
     * @param array<string, mixed> $dati
     *
     * @return array{email: string, nome: string, cognome: string, user: ?User}
     */
    private function persona(array $dati, string $campo = 'persona'): array
    {
        $email = $this->email((string) ($dati['email'] ?? ''), $campo.'.email');
        $user = $this->users->findOneByEmail($email);
        if ($user instanceof User && null !== $user->getPlatformRole()) {
            throw new Conflitto(sprintf('%s: gli account del team del portale non possono diventare membri da qui.', $campo));
        }
        if ($user instanceof User) {
            return ['email' => $email, 'nome' => $user->getFirstName(), 'cognome' => $user->getLastName(), 'user' => $user];
        }

        return ['email' => $email, 'nome' => $this->testo($dati, 'nome', $campo.'.nome'), 'cognome' => $this->testo($dati, 'cognome', $campo.'.cognome'), 'user' => null];
    }

    /** @param array{email: string, nome: string, cognome: string, user: ?User} $spec */
    private function utente(array $spec): User
    {
        if (null !== $spec['user']) {
            return $spec['user'];
        }
        // Senza password: si entra solo dal link di attivazione (o con Google).
        $user = new User($spec['email'], $spec['nome'], $spec['cognome']);
        $this->em->persist($user);
        $this->audit->log('portale.utente_creato', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['email' => $user->getEmail()]);

        return $user;
    }

    private function benvenuto(Membership $m, bool $nuovo): void
    {
        $tenant = $m->getTenant();
        if ($nuovo) {
            $this->account->inviaAttivazione($m->getUser(), $tenant->getName());

            return;
        }
        $this->avvisa($m->getUser(), sprintf('il team di CartellaViva ti ha aggiunto a "%s" con il ruolo "%s". Lo trovi nel menu in alto, tra le tue famiglie ed enti.', $tenant->getName(), $m->getRole()->getName()));
    }

    /** @param list<User> $esclusi */
    private function avvisaProprietari(Tenant $tenant, string $messaggio, array $esclusi = []): void
    {
        foreach ($this->memberships->findForTenant($tenant) as $m) {
            $u = $m->getUser();
            if (!$m->isProprietarioAttivo()) {
                continue;
            }
            if ([] !== array_filter($esclusi, static fn (User $e) => $e->getId()->equals($u->getId()))) {
                continue;
            }
            $this->avvisa($u, ucfirst($messaggio));
        }
    }

    private function avvisa(User $user, string $messaggio): void
    {
        if (AccountService::inAttesaDiAttivazione($user) || $user->isEliminato()) {
            return; // riceverà già il link di attivazione, o non c'è più
        }
        $this->inviaA($user->getEmail(), $user->getFirstName(), $messaggio);
    }

    private function inviaA(string $email, string $nome, string $messaggio): void
    {
        $this->posta->invia($email, 'Modifica ai tuoi accessi su CartellaViva', 'emails/portale_accesso.html.twig', ['nome' => $nome, 'messaggio' => ucfirst($messaggio), 'url' => rtrim($this->frontendUrl, '/').'/']);
    }

    private function ruolo(Tenant $tenant, string $ruolo): Role
    {
        $role = $this->roles->findAssignableById($tenant, $ruolo)
            ?? (($r = $this->roles->findSystemRole($ruolo)) instanceof Role && $r->isAssignableIn($tenant) ? $r : null);

        return $role ?? throw new DatiNonValidi('ruolo: non utilizzabile in questo tenant.');
    }

    private function assertModificabile(Tenant $tenant): void
    {
        if ($tenant->isInEliminazione()) {
            throw new Conflitto('Il tenant è in eliminazione: non si modifica.');
        }
    }

    private function email(string $email, string $campo): string
    {
        $email = User::normalizeEmail($email);
        if (\count($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)])) > 0) {
            throw new DatiNonValidi($campo.': indirizzo email non valido.');
        }

        return $email;
    }

    /** @param array<string, mixed> $dati */
    private function testo(array $dati, string $chiave, string $campo): string
    {
        $v = trim((string) ($dati[$chiave] ?? ''));
        if ('' === $v || mb_strlen($v) > 100) {
            throw new DatiNonValidi($campo.': obbligatorio (massimo 100 caratteri).');
        }

        return $v;
    }

    /**
     * Transazione manuale, come MembershipManager: un errore annulla tutto
     * senza chiudere l'EntityManager.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function transazione(callable $fn): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $fn();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
