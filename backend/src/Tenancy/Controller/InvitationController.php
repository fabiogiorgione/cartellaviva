<?php

namespace App\Tenancy\Controller;

use App\Account\Entity\User;
use App\Account\Repository\UserRepository;
use App\Tenancy\Dto\AcceptInvitationRequest;
use App\Tenancy\Entity\Invitation;
use App\Tenancy\InvitationService;
use App\Tenancy\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Endpoint pubblici (il token dell'invito fa da credenziale):
 *   GET  /api/invitations/preview?token=...  dati da mostrare prima di accettare
 *   POST /api/invitations/accept             accetta; se non autenticato crea l'account
 */
#[Route('/api/invitations')]
final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        #[Autowire(service: 'limiter.public_auth')]
        private readonly RateLimiterFactoryInterface $publicAuthLimiter,
    ) {
    }

    #[Route('/preview', name: 'api_invitation_preview', methods: ['GET'])]
    public function preview(Request $request, UserRepository $users): JsonResponse
    {
        $invitation = $this->findByToken($request, (string) $request->query->get('token'));

        return $this->json([
            'tenantName' => $invitation->getTenant()->getName(),
            'email' => $invitation->getEmail(),
            'roleName' => $invitation->getRole()->getName(),
            'invitedBy' => $invitation->getInvitedBy()?->getFullName(),
            'status' => $invitation->getStatus(),
            'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM),
            'accountExists' => null !== $users->findOneByEmail($invitation->getEmail()),
        ]);
    }

    #[Route('/accept', name: 'api_invitation_accept', methods: ['POST'])]
    public function accept(
        #[MapRequestPayload] AcceptInvitationRequest $payload,
        Request $request,
        InvitationService $service,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
    ): JsonResponse {
        $invitation = $this->findByToken($request, $payload->token);
        $user = $this->getUser();

        if (!$user instanceof User) {
            // Nessuna sessione: se l'account esiste va fatto prima il login (poi si riaccetta autenticati).
            if (null !== $users->findOneByEmail($invitation->getEmail())) {
                throw new ConflictHttpException('Esiste già un account per questa email: accedi e accetta l\'invito.');
            }
            // Controllo anticipato: nessun account creato per inviti scaduti o revocati.
            $service->assertPending($invitation);
            $violations = $validator->validate($payload, null, ['registration']);
            if (\count($violations) > 0) {
                throw new ValidationFailedException($payload, $violations);
            }

            $user = new User($invitation->getEmail(), trim((string) $payload->firstName), trim((string) $payload->lastName));
            $user->setPassword($hasher->hashPassword($user, (string) $payload->password));
            // Il link dell'invito è arrivato a questo indirizzo: è già verificato.
            $user->segnaEmailVerificata();
            $em->persist($user);
        }

        $membership = $service->accept($invitation, $user);

        return $this->json([
            'membershipId' => (string) $membership->getId(),
            'tenantId' => (string) $membership->getTenant()->getId(),
            'email' => $user->getEmail(),
        ], Response::HTTP_CREATED);
    }

    private function findByToken(Request $request, string $token): Invitation
    {
        if (!$this->publicAuthLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }

        return $this->invitations->findOneByPlainToken($token) ?? throw new NotFoundHttpException('Invito non valido.');
    }
}
