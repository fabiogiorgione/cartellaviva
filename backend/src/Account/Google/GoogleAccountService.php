<?php

namespace App\Account\Google;

use App\Account\AccountService;
use App\Account\Entity\User;
use App\Account\Registrazione;
use App\Account\Repository\UserRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Collega un'identità Google a un account CartellaViva:
 * 1. account già collegato (stesso "sub")        -> login;
 * 2. account esistente con la stessa email      -> collegamento automatico
 *    (l'email è verificata da Google, quindi la persona ne è titolare; se l'account
 *    non era mai stato verificato, chi l'aveva registrato perde password e sessioni);
 * 3. nessun account                             -> nuovo utente senza password,
 *    con anagrafica da completare (onboarding) e senza tenant finché non la completa.
 *    Con la registrazione chiusa il caso 3 è rifiutato (HTTP 403).
 */
final class GoogleAccountService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly AccountService $account,
        private readonly Registrazione $registrazione,
    ) {
    }

    /** @return array{0: User, 1: bool} utente e flag "appena creato" */
    public function resolve(GoogleIdentity $identity): array
    {
        $user = $this->users->findOneBy(['googleSubject' => $identity->subject]);
        if (null !== $user) {
            return [$user, false];
        }

        $user = $this->users->findOneByEmail($identity->email);
        if (null !== $user) {
            if (null !== $user->getGoogleSubject()) {
                // Email uguale ma collegata a un altro account Google: non sovrascriviamo.
                throw new Conflitto('Questo account è già collegato a un altro profilo Google.');
            }
            $this->account->rivendicaConGoogle($user);
            $user->linkGoogle($identity->subject);
            $user->segnaEmailVerificata();
            $this->em->flush();
            $this->audit->log('user.google_linked', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['via' => 'email_match'], actor: $user);

            return [$user, false];
        }

        if (!$this->registrazione->isAperta()) {
            $this->audit->log('auth.login', AuditOutcome::FAILURE, context: ['method' => 'google', 'reason' => 'registrazione_chiusa'], actorEmail: User::normalizeEmail($identity->email));
            $this->registrazione->richiediAperta();
        }

        $user = new User($identity->email, $identity->givenName, $identity->familyName);
        $user->linkGoogle($identity->subject);
        $user->segnaEmailVerificata();
        $this->em->persist($user);
        $this->em->flush();
        $this->audit->log('user.registered', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['method' => 'google'], actor: $user);

        return [$user, true];
    }

    public function link(User $user, GoogleIdentity $identity): void
    {
        $other = $this->users->findOneBy(['googleSubject' => $identity->subject]);
        if (null !== $other && !$other->getId()->equals($user->getId())) {
            throw new Conflitto('Questo profilo Google è già collegato a un altro account.');
        }
        $user->linkGoogle($identity->subject);
        $this->em->flush();
        $this->audit->log('user.google_linked', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['via' => 'settings'], actor: $user);
    }
}
