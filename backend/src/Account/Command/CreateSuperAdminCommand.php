<?php

namespace App\Account\Command;

use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Il superadmin non si crea via API: solo da console sul server.
 * Se l'utente esiste già viene promosso (la password resta invariata).
 */
#[AsCommand(name: 'app:user:create-superadmin', description: 'Crea (o promuove) l\'amministratore della piattaforma')]
final class CreateSuperAdminCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email dell\'amministratore')] string $email,
        #[Argument('Nome')] string $firstName = 'Admin',
        #[Argument('Cognome')] string $lastName = 'CartellaViva',
    ): int {
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $password = $io->askHidden('Password (min 12 caratteri)', static function (?string $value): string {
                if (null === $value || mb_strlen($value) < 12) {
                    throw new \RuntimeException('La password deve avere almeno 12 caratteri.');
                }

                return $value;
            });
            $user = new User($email, $firstName, $lastName);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $user->segnaEmailVerificata();
            $this->em->persist($user);
        }

        $user->setSuperAdmin(true);
        $this->em->flush();
        $this->audit->log('user.superadmin_granted', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['via' => 'console']);

        $io->success(sprintf('%s è superadmin.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
