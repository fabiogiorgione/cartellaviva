<?php

namespace App\Account;

use App\Account\Entity\RefreshToken;
use App\Account\Entity\TokenAccount;
use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonPiuValido;
use App\Shared\Errore\TroppiTentativi;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Password dimenticata, verifica dell'email, cambio di password ed email.
 *
 * - Le richieste pubbliche rispondono sempre allo stesso modo: non rivelano
 *   se un indirizzo ha un account.
 * - Ogni cambio di credenziali chiude tutte le sessioni (refresh token
 *   eliminati e JWT precedenti rifiutati) e manda un avviso.
 * - La verifica in due passaggi resta: il reset della password non la tocca.
 * - Le email contengono solo il link, mai dati dei documenti.
 */
final class AccountService
{
    /** Link dello stesso tipo che un utente può ricevere in un'ora. */
    private const MAX_INVII_ORA = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        private readonly Posta $posta,
        private readonly AuditLogger $audit,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    // ---- Password dimenticata ----------------------------------------------

    /** Pubblica. Funziona anche per gli account creati con Google (imposta la prima password). */
    public function richiediReset(string $email): void
    {
        $user = $this->users->findOneByEmail($email);
        if (!$user instanceof User || !$user->isActive() || $this->troppiInvii($user, TokenAccount::RESET_PASSWORD)) {
            $this->audit->log('user.password_reset_requested', AuditOutcome::FAILURE, context: ['reason' => $user instanceof User ? 'throttled_or_inactive' : 'unknown_email'], actorEmail: User::normalizeEmail($email));

            return;
        }
        $token = $this->nuovoToken($user, TokenAccount::RESET_PASSWORD);
        $this->audit->log('user.password_reset_requested', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);
        $this->email($user->getEmail(), $user, 'Reimposta la password di CartellaViva', 'emails/account_reset_password.html.twig', [
            'url' => $this->url('/auth/reset-password?token='.$token),
        ]);
    }

    /** Per la pagina del link: a chi si sta reimpostando la password (email mascherata). */
    public function anteprimaReset(string $token): string
    {
        return self::maschera($this->daToken($token, TokenAccount::RESET_PASSWORD)->getUser()->getEmail());
    }

    public function reimpostaPassword(string $token, string $password): User
    {
        $t = $this->daToken($token, TokenAccount::RESET_PASSWORD);
        $user = $t->getUser();
        $this->validaPassword($password);
        $t->usa();
        $user->setPassword($this->hasher->hashPassword($user, $password));
        // Chi ha ricevuto il link controlla la casella.
        $user->segnaEmailVerificata();
        $this->chiudiSessioni($user);
        $this->em->flush();
        $this->audit->log('user.password_reset', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);
        $this->avvisoPasswordCambiata($user);

        return $user;
    }

    // ---- Attivazione (account creato dal portale) ----------------------------

    /** Primo link, o "reinvia" dal portale. $dove: famiglia o ente per cui è stato creato. */
    public function inviaAttivazione(User $user, ?string $dove = null): void
    {
        if (!self::inAttesaDiAttivazione($user)) {
            throw new Conflitto('L\'account è già attivo: per una nuova password si usa "Password dimenticata".');
        }
        if ($this->troppiInvii($user, TokenAccount::ATTIVAZIONE)) {
            throw new TroppiTentativi('Link già inviato più volte nell\'ultima ora: riprova più tardi.');
        }
        $token = $this->nuovoToken($user, TokenAccount::ATTIVAZIONE);
        $this->email($user->getEmail(), $user, 'Attiva il tuo account CartellaViva', 'emails/account_attivazione.html.twig', [
            'url' => $this->url('/auth/attiva?token='.$token),
            'dove' => $dove,
        ]);
    }

    public function anteprimaAttivazione(string $token): string
    {
        return self::maschera($this->daToken($token, TokenAccount::ATTIVAZIONE)->getUser()->getEmail());
    }

    /** Sceglie la prima password; il link è arrivato alla casella, quindi l'email è verificata. */
    public function attiva(string $token, string $password): User
    {
        $t = $this->daToken($token, TokenAccount::ATTIVAZIONE);
        $user = $t->getUser();
        $this->validaPassword($password);
        $t->usa();
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->segnaEmailVerificata();
        $this->chiudiSessioni($user);
        $this->em->flush();
        $this->audit->log('user.account_attivato', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);

        return $user;
    }

    /** Creato dal portale e mai entrato: niente password e niente Google. */
    public static function inAttesaDiAttivazione(User $user): bool
    {
        return !$user->hasPassword() && null === $user->getGoogleSubject();
    }

    // ---- Cambio password (autenticato) --------------------------------------

    /** Chi non ha ancora una password (account Google) la imposta senza quella attuale. */
    public function cambiaPassword(User $user, ?string $attuale, string $nuova): void
    {
        if ($user->hasPassword() && (null === $attuale || !$this->hasher->isPasswordValid($user, $attuale))) {
            $this->audit->log('user.password_changed', AuditOutcome::FAILURE, context: ['reason' => 'wrong_password'], actor: $user);
            throw new DatiNonValidi('La password attuale non è corretta.');
        }
        $this->validaPassword($nuova);
        $prima = $user->hasPassword();
        $user->setPassword($this->hasher->hashPassword($user, $nuova));
        $this->chiudiSessioni($user);
        $this->em->flush();
        $this->audit->log($prima ? 'user.password_changed' : 'user.password_set', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);
        $this->avvisoPasswordCambiata($user);
    }

    // ---- Verifica dell'email ------------------------------------------------

    /** Dopo la registrazione e su richiesta ("invia di nuovo"). */
    public function inviaVerifica(User $user): void
    {
        if ($user->isEmailVerificata()) {
            return;
        }
        if ($this->troppiInvii($user, TokenAccount::VERIFICA_EMAIL)) {
            throw new TroppiTentativi('Ti abbiamo già mandato diversi link: controlla anche nella posta indesiderata, o riprova tra un\'ora.');
        }
        $token = $this->nuovoToken($user, TokenAccount::VERIFICA_EMAIL);
        $this->email($user->getEmail(), $user, 'Conferma il tuo indirizzo email', 'emails/account_verifica_email.html.twig', [
            'url' => $this->url('/auth/verifica-email?token='.$token),
        ]);
    }

    /** Pubblica: il link può essere aperto su un altro dispositivo. */
    public function verificaEmail(string $token): User
    {
        $t = $this->daToken($token, TokenAccount::VERIFICA_EMAIL);
        $t->usa();
        $t->getUser()->segnaEmailVerificata();
        $this->em->flush();
        $this->audit->log('user.email_verified', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $t->getUser()->getId(), actor: $t->getUser());

        return $t->getUser();
    }

    // ---- Cambio email --------------------------------------------------------

    /** Il link va al nuovo indirizzo; il vecchio riceve un avviso. */
    public function richiediCambioEmail(User $user, string $nuova, ?string $password): void
    {
        if (!$user->hasPassword()) {
            throw new Conflitto('Imposta prima una password: serve per confermare il cambio di email.');
        }
        if (null === $password || !$this->hasher->isPasswordValid($user, $password)) {
            $this->audit->log('user.email_change_requested', AuditOutcome::FAILURE, context: ['reason' => 'wrong_password'], actor: $user);
            throw new DatiNonValidi('La password non è corretta.');
        }
        $nuova = User::normalizeEmail($nuova);
        if (\count($this->validator->validate($nuova, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)])) > 0) {
            throw new DatiNonValidi('Indirizzo email non valido.');
        }
        if ($nuova === $user->getEmail()) {
            throw new DatiNonValidi('È già il tuo indirizzo.');
        }
        if (null !== $this->users->findOneByEmail($nuova)) {
            throw new Conflitto('Questo indirizzo è già usato da un altro account.');
        }
        if ($this->troppiInvii($user, TokenAccount::CAMBIO_EMAIL)) {
            throw new TroppiTentativi('Troppe richieste: riprova tra un\'ora.');
        }
        $token = $this->nuovoToken($user, TokenAccount::CAMBIO_EMAIL, $nuova);
        $this->audit->log('user.email_change_requested', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);
        $this->email($nuova, $user, 'Conferma il nuovo indirizzo email', 'emails/account_cambio_email.html.twig', [
            'url' => $this->url('/auth/cambio-email?token='.$token),
        ]);
        $this->email($user->getEmail(), $user, 'Richiesta di cambio dell\'indirizzo email', 'emails/account_avviso.html.twig', [
            'messaggio' => sprintf('è stato chiesto di sostituire l\'indirizzo del tuo account con %s. Il cambio avviene solo se viene confermato dal link inviato al nuovo indirizzo.', self::maschera($nuova)),
        ]);
    }

    public function confermaCambioEmail(string $token): User
    {
        $t = $this->daToken($token, TokenAccount::CAMBIO_EMAIL);
        $user = $t->getUser();
        $nuova = (string) $t->getNuovaEmail();
        if (null !== $this->users->findOneByEmail($nuova)) {
            throw new Conflitto('Nel frattempo questo indirizzo è stato usato da un altro account.');
        }
        $vecchia = $user->getEmail();
        $t->usa();
        // Prima del cambio: i refresh token sono legati all'indirizzo.
        $this->chiudiSessioni($user);
        $user->cambiaEmail($nuova);
        $this->em->flush();
        $this->audit->log('user.email_changed', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['da' => self::maschera($vecchia)], actor: $user);
        $this->email($vecchia, $user, 'L\'indirizzo email del tuo account è cambiato', 'emails/account_avviso.html.twig', [
            'messaggio' => sprintf('l\'indirizzo del tuo account CartellaViva ora è %s. Le sessioni aperte sono state chiuse.', self::maschera($nuova)),
        ]);

        return $user;
    }

    // ---- Supporto -------------------------------------------------------------

    /**
     * Un account mai verificato con l'email di chi accede con Google: la
     * casella è di chi ha Google. Chi l'aveva registrato prima (magari per
     * appropriarsene) perde password, 2FA e sessioni.
     */
    public function rivendicaConGoogle(User $user): void
    {
        if ($user->isEmailVerificata()) {
            return;
        }
        $user->setPassword('');
        $user->disableTwoFactor();
        $user->segnaEmailVerificata();
        $this->chiudiSessioni($user);
        $this->audit->log('user.account_reclaimed', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['via' => 'google'], actor: $user);
    }

    public function chiudiSessioni(User $user): void
    {
        $user->segnaCredenzialiCambiate();
        $this->em->createQuery('DELETE FROM '.RefreshToken::class.' r WHERE r.username = :e')->setParameter('e', $user->getEmail())->execute();
        // Anche un cambio email in sospeso: chi l'ha chiesto con la password vecchia
        // (magari rubata) non deve poter portarsi via l'account dopo il cambio.
        $this->em->createQuery('UPDATE '.TokenAccount::class.' t SET t.usatoIl = :ora WHERE t.user = :u AND t.tipo = :tipo AND t.usatoIl IS NULL')
            ->setParameter('ora', new \DateTimeImmutable())->setParameter('u', $user)->setParameter('tipo', TokenAccount::CAMBIO_EMAIL)->execute();
    }

    private function validaPassword(string $password): void
    {
        $violazioni = $this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 10, max: 4096), new Assert\NotCompromisedPassword(skipOnError: true)]);
        if (\count($violazioni) > 0) {
            throw new DatiNonValidi('password: '.$violazioni->get(0)->getMessage());
        }
    }

    private function troppiInvii(User $user, string $tipo): bool
    {
        $n = (int) $this->em->createQueryBuilder()->select('COUNT(t.id)')->from(TokenAccount::class, 't')
            ->where('t.user = :u AND t.tipo = :tipo AND t.createdAt > :da')
            ->setParameter('u', $user)->setParameter('tipo', $tipo)->setParameter('da', new \DateTimeImmutable('-1 hour'))
            ->getQuery()->getSingleScalarResult();

        return $n >= self::MAX_INVII_ORA;
    }

    /** Un nuovo link invalida i precedenti dello stesso tipo. */
    private function nuovoToken(User $user, string $tipo, ?string $nuovaEmail = null): string
    {
        $this->em->createQuery('UPDATE '.TokenAccount::class.' t SET t.usatoIl = :ora WHERE t.user = :u AND t.tipo = :tipo AND t.usatoIl IS NULL')
            ->setParameter('ora', new \DateTimeImmutable())->setParameter('u', $user)->setParameter('tipo', $tipo)->execute();
        $token = TokenCasuale::nuovo();
        $this->em->persist(new TokenAccount($user, $tipo, hash('sha256', $token), $nuovaEmail));
        $this->em->flush();

        return $token;
    }

    private function daToken(string $token, string $tipo): TokenAccount
    {
        $t = '' === trim($token) ? null : $this->em->getRepository(TokenAccount::class)->findOneBy(['tokenHash' => hash('sha256', trim($token)), 'tipo' => $tipo]);
        if (!$t instanceof TokenAccount || !$t->isUsabile() || !$t->getUser()->isActive()) {
            throw new NonPiuValido('Link scaduto o già usato: richiedine uno nuovo.');
        }

        return $t;
    }

    private function avvisoPasswordCambiata(User $user): void
    {
        $this->email($user->getEmail(), $user, 'La password del tuo account è cambiata', 'emails/account_avviso.html.twig', [
            'messaggio' => 'la password del tuo account CartellaViva è stata cambiata e le sessioni aperte sono state chiuse.',
        ]);
    }

    /** @param array<string, mixed> $context */
    private function email(string $to, User $user, string $subject, string $template, array $context): void
    {
        $this->posta->invia($to, $subject, $template, ['nome' => $user->getFirstName(), 'recupero' => $this->url('/auth/forgot_password')] + $context);
    }

    private function url(string $path): string
    {
        return rtrim($this->frontendUrl, '/').$path;
    }

    public static function maschera(string $email): string
    {
        [$nome, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($nome, 0, 1).'•••@'.$dominio;
    }
}
