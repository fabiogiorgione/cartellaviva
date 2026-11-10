<?php

namespace App\Account\Controller;

use App\Account\AccountService;
use App\Account\Dto\RegisterRequest;
use App\Account\Entity\User;
use App\Account\Registrazione;
use App\Account\Repository\UserRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\TenantProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Login, refresh e logout sono gestiti dal firewall (json_login + lexik + gesdinet):
 *   POST /api/auth/login    {email, password}   -> {token, refresh_token}
 *   POST /api/auth/refresh  {refresh_token}     -> {token, refresh_token} (rotazione)
 *   POST /api/auth/logout   {refresh_token}     -> 204, invalida il refresh token
 */
#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    /**
     * Pubblico: l'app mostra "Registrati" solo se la registrazione è aperta.
     */
    #[Route('/registrazione', name: 'api_registrazione_stato', methods: ['GET'])]
    public function statoRegistrazione(Registrazione $registrazione): JsonResponse
    {
        return $this->json(['aperta' => $registrazione->isAperta()]);
    }

    /**
     * Registrazione pubblica: crea l'utente e il suo tenant privato, di cui è owner.
     * Con la registrazione chiusa risponde 403 (si entra solo su invito).
     */
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterRequest $payload,
        Request $request,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        TenantProvisioner $provisioner,
        AuditLogger $audit,
        AccountService $account,
        Registrazione $registrazione,
        #[Autowire(service: 'limiter.public_auth')] RateLimiterFactoryInterface $publicAuthLimiter,
    ): JsonResponse {
        if (!$publicAuthLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }

        // Prima del controllo sull'email: a registrazione chiusa non si scopre se un account esiste.
        if (!$registrazione->isAperta()) {
            $audit->log('user.registered', AuditOutcome::FAILURE, context: ['reason' => 'registrazione_chiusa'], actorEmail: User::normalizeEmail($payload->email));
            $registrazione->richiediAperta();
        }

        if (null !== $users->findOneByEmail($payload->email)) {
            $audit->log('user.registered', AuditOutcome::FAILURE, context: ['reason' => 'email_taken'], actorEmail: User::normalizeEmail($payload->email));
            throw new ConflictHttpException('Esiste già un account con questa email.');
        }

        $user = new User($payload->email, trim($payload->firstName), trim($payload->lastName));
        $user->setPassword($hasher->hashPassword($user, $payload->password));
        $em->persist($user);

        $tenantName = trim((string) $payload->tenantName) ?: 'Famiglia '.$user->getLastName();
        $membership = $provisioner->createWithOwner($user, $tenantName, TenantType::PRIVATO);

        $audit->log('user.registered', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), tenantId: $membership->getTenant()->getId(), actor: $user);
        // Si entra subito; finché non si apre il link, niente inviti né collegamenti (EmailVerificata).
        $account->inviaVerifica($user);

        return $this->json([
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'tenantId' => (string) $membership->getTenant()->getId(),
        ], Response::HTTP_CREATED);
    }
}
