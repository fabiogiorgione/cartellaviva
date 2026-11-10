<?php

namespace App\Account\TwoFactor;

use App\Account\Entity\TwoFactorBackupCode;
use App\Account\Entity\TwoFactorChallenge;
use App\Account\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\TroppiTentativi;
use App\Shared\TokenCasuale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Ciclo di vita della 2FA: attivazione (segreto provvisorio + conferma con un
 * codice), disattivazione, codici di recupero, challenge del login.
 */
final class TwoFactorService
{
    public const BACKUP_CODES = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TotpService $totp,
        private readonly SecretEncryptor $encryptor,
        private readonly AuditLogger $audit,
        #[Autowire(service: 'limiter.second_factor')]
        private readonly RateLimiterFactoryInterface $secondFactorLimiter,
    ) {
    }

    /** @return array{secret: string, otpauthUri: string} */
    public function startEnrollment(User $user): array
    {
        if ($user->isTwoFactorEnabled()) {
            throw new Conflitto('La verifica in due passaggi è già attiva.');
        }
        $secret = $this->totp->generateSecret();
        $user->setTotpPendingSecret($this->encryptor->encrypt($secret));
        $this->em->flush();
        $this->audit->log('2fa.setup_started', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);

        return ['secret' => $secret, 'otpauthUri' => $this->totp->provisioningUri($user, $secret)];
    }

    /** @return list<string> codici di recupero in chiaro (mostrati una sola volta) */
    public function confirmEnrollment(User $user, string $code): array
    {
        $pending = $user->getTotpPendingSecret();
        if (null === $pending) {
            throw new Conflitto('Nessuna attivazione in corso: ricomincia dalla configurazione.');
        }
        $timestep = $this->totp->verify($pending, $code, null);
        if (null === $timestep) {
            $this->audit->log('2fa.enabled', AuditOutcome::FAILURE, resourceType: 'user', resourceId: (string) $user->getId(), context: ['reason' => 'invalid_code'], actor: $user);
            throw new DatiNonValidi('Codice non valido: controlla l\'ora del telefono e riprova.');
        }

        $user->enableTwoFactor($pending, $timestep);
        $codes = $this->replaceBackupCodes($user);
        $this->em->flush();
        $this->audit->log('2fa.enabled', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);

        return $codes;
    }

    public function disable(User $user, string $code): void
    {
        $this->assertSecondFactor($user, $code, '2fa.disabled');
        $user->disableTwoFactor();
        $this->deleteBackupCodes($user);
        $this->em->flush();
        $this->audit->log('2fa.disabled', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);
    }

    /** @return list<string> */
    public function regenerateBackupCodes(User $user, string $code): array
    {
        $this->assertSecondFactor($user, $code, '2fa.backup_codes_regenerated');
        $codes = $this->replaceBackupCodes($user);
        $this->em->flush();
        $this->audit->log('2fa.backup_codes_regenerated', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);

        return $codes;
    }

    public function remainingBackupCodes(User $user): int
    {
        return \count(array_filter($this->backupCodes($user), static fn (TwoFactorBackupCode $c) => !$c->isUsed()));
    }

    /** Challenge del login: restituisce il token in chiaro da inviare al client. */
    public function createChallenge(User $user, string $method): string
    {
        $token = TokenCasuale::nuovo();
        $this->em->persist(new TwoFactorChallenge($user, $token, $method));
        $this->em->flush();

        return $token;
    }

    /**
     * Verifica un codice TOTP o di recupero. In caso di successo aggiorna
     * l'anti-replay o consuma il codice di recupero. Restituisce il tipo usato.
     */
    public function verifyCode(User $user, string $code): ?string
    {
        $secret = $user->getTotpSecret();
        if (null === $secret) {
            return null;
        }
        // Limite per utente, comune a login e conferme: ogni sessione da sola non basta.
        $limite = $this->secondFactorLimiter->create($user->getId()->toRfc4122());
        if (0 === $limite->consume(0)->getRemainingTokens()) {
            throw new TroppiTentativi('Troppi codici errati: riprova tra un quarto d\'ora.');
        }
        $timestep = $this->totp->verify($secret, $code, $user->getTotpLastTimestep());
        if (null !== $timestep) {
            $user->setTotpLastTimestep($timestep);
            $limite->reset();

            return 'totp';
        }
        foreach ($this->backupCodes($user) as $backup) {
            if ($backup->matches($code)) {
                $backup->markUsed();
                $limite->reset();

                return 'backup_code';
            }
        }
        $limite->consume();

        return null;
    }

    /** Conferma di un'operazione critica con il secondo fattore (409 se la 2FA non è attiva, 422 se il codice è errato). */
    public function assertSecondFactor(User $user, string $code, string $action): void
    {
        if (!$user->isTwoFactorEnabled()) {
            throw new Conflitto('La verifica in due passaggi non è attiva.');
        }
        if (null === $this->verifyCode($user, $code)) {
            $this->audit->log($action, AuditOutcome::FAILURE, resourceType: 'user', resourceId: (string) $user->getId(), context: ['reason' => 'invalid_code'], actor: $user);
            throw new DatiNonValidi('Codice non valido.');
        }
    }

    /** @return list<string> */
    private function replaceBackupCodes(User $user): array
    {
        $this->deleteBackupCodes($user);
        $plain = [];
        for ($i = 0; $i < self::BACKUP_CODES; ++$i) {
            $raw = strtolower(bin2hex(random_bytes(5)));
            $code = substr($raw, 0, 5).'-'.substr($raw, 5, 5);
            $plain[] = $code;
            $this->em->persist(new TwoFactorBackupCode($user, $code));
        }

        return $plain;
    }

    private function deleteBackupCodes(User $user): void
    {
        foreach ($this->backupCodes($user) as $code) {
            $this->em->remove($code);
        }
    }

    /** @return list<TwoFactorBackupCode> */
    private function backupCodes(User $user): array
    {
        return $this->em->getRepository(TwoFactorBackupCode::class)->findBy(['user' => $user]);
    }
}
