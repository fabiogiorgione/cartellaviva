<?php

namespace App\Account\Controller;

use App\Account\Entity\User;
use App\Account\Google\GoogleAccountService;
use App\Account\Google\GoogleIdTokenVerifier;
use App\Account\Google\InvalidGoogleTokenException;
use App\Account\TwoFactor\TwoFactorService;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sicurezza del proprio account: verifica in due passaggi e collegamento Google.
 */
#[Route('/api/me')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AccountSecurityController extends AbstractController
{
    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    #[Route('/security', name: 'api_me_security', methods: ['GET'])]
    public function status(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json([
            'twoFactorEnabled' => $user->isTwoFactorEnabled(),
            'twoFactorEnabledAt' => $user->getTotpEnabledAt()?->format(\DATE_ATOM),
            'backupCodesRemaining' => $user->isTwoFactorEnabled() ? $this->twoFactor->remainingBackupCodes($user) : 0,
            'googleLinked' => null !== $user->getGoogleSubject(),
            'hasPassword' => $user->hasPassword(),
        ]);
    }

    /** Passo 1: genera il segreto; l'app lo mostra come QR code da scansionare. */
    #[Route('/2fa/setup', name: 'api_me_2fa_setup', methods: ['POST'])]
    public function setup(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->twoFactor->startEnrollment($user));
    }

    /** Passo 2: conferma con il primo codice generato; restituisce i codici di recupero. */
    #[Route('/2fa/enable', name: 'api_me_2fa_enable', methods: ['POST'])]
    public function enable(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return $this->json(['backupCodes' => $this->twoFactor->confirmEnrollment($user, $this->code($request))]);
    }

    #[Route('/2fa/disable', name: 'api_me_2fa_disable', methods: ['POST'])]
    public function disable(#[CurrentUser] User $user, Request $request): Response
    {
        $this->twoFactor->disable($user, $this->code($request));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/2fa/backup-codes', name: 'api_me_2fa_backup_codes', methods: ['POST'])]
    public function backupCodes(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return $this->json(['backupCodes' => $this->twoFactor->regenerateBackupCodes($user, $this->code($request))]);
    }

    #[Route('/google', name: 'api_me_google_link', methods: ['POST'])]
    public function linkGoogle(#[CurrentUser] User $user, Request $request, GoogleIdTokenVerifier $verifier, GoogleAccountService $accounts, AuditLogger $audit): Response
    {
        $idToken = (string) ($this->payload($request)['idToken'] ?? '');
        try {
            $identity = $verifier->verify($idToken);
        } catch (InvalidGoogleTokenException $e) {
            $audit->log('user.google_linked', AuditOutcome::FAILURE, context: ['reason' => $e->getMessage()], actor: $user);
            throw new UnprocessableEntityHttpException('Token Google non valido.', $e);
        }
        $accounts->link($user, $identity);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/google', name: 'api_me_google_unlink', methods: ['DELETE'])]
    public function unlinkGoogle(#[CurrentUser] User $user, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        if (!$user->hasPassword()) {
            throw new ConflictHttpException('Imposta prima una password: altrimenti non potresti più accedere.');
        }
        $user->linkGoogle(null);
        $em->flush();
        $audit->log('user.google_unlinked', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), actor: $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function code(Request $request): string
    {
        $code = $this->payload($request)['code'] ?? null;
        if (!\is_string($code) || '' === trim($code)) {
            throw new UnprocessableEntityHttpException('Inserisci il codice.');
        }

        return $code;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : [];
    }
}
