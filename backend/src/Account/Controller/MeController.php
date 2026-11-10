<?php

namespace App\Account\Controller;

use App\Account\Entity\User;
use App\Tenancy\Repository\MembershipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Profilo dell'utente corrente con, per ogni tenant, ruolo e permessi effettivi.
 * Il frontend lo usa SOLO per abilitare/disabilitare le azioni in UI: ogni
 * endpoint ricontrolla comunque i permessi.
 */
final class MeController extends AbstractController
{
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[CurrentUser] User $user, MembershipRepository $memberships): JsonResponse
    {
        $tenants = [];
        foreach ($memberships->findActiveForUser($user) as $membership) {
            $tenant = $membership->getTenant();
            $role = $membership->getRole();
            $tenants[] = [
                'membershipId' => (string) $membership->getId(),
                'tenant' => [
                    'id' => (string) $tenant->getId(),
                    'name' => $tenant->getName(),
                    'type' => $tenant->getType()->value,
                ],
                'role' => [
                    'id' => (string) $role->getId(),
                    'slug' => $role->getSlug(),
                    'name' => $role->getName(),
                ],
                'permissions' => $role->getEffectivePermissionCodes(),
            ];
        }

        return $this->json([
            'id' => (string) $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'isSuperAdmin' => $user->isSuperAdmin(),
            // Accesso al portale: superadmin | supporto | sola_lettura | null.
            'platformRole' => $user->getPlatformRole(),
            // Dopo un recupero dell'accesso la 2FA va riattivata subito.
            'twoFactorObbligatoria' => $user->isTwoFactorObbligatoria(),
            // Falso finché l'anagrafica non è compilata: il frontend porta all'onboarding.
            'profileCompleted' => $user->isProfileCompleted(),
            'twoFactorEnabled' => $user->isTwoFactorEnabled(),
            'googleLinked' => null !== $user->getGoogleSubject(),
            'hasPassword' => $user->hasPassword(),
            // Falso dopo la registrazione finché non si apre il link: il frontend mostra un avviso.
            'emailVerificata' => $user->isEmailVerificata(),
            // Falso al primo accesso: l'app mostra una breve introduzione.
            'introVista' => $user->isIntroVista(),
            'memberships' => $tenants,
        ]);
    }
}
