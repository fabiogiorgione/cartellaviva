<?php

namespace App\Account\Controller;

use App\Account\Entity\User;
use App\Account\Push\PushGatewayInterface;
use App\Account\Push\PushNotifier;
use App\Shared\Http\JsonBodyTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Telefoni per le notifiche push dell'utente corrente.
 *   POST   /api/me/dispositivi {token, piattaforma: android|ios}
 *   DELETE /api/me/dispositivi {token}   (al logout)
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DispositiviController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly PushNotifier $push,
        private readonly PushGatewayInterface $gateway,
    ) {
    }

    #[Route('/api/me/dispositivi', name: 'api_me_dispositivo_registra', methods: ['POST'])]
    public function registra(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $body = $this->body($request);
        $this->push->registra($user, (string) ($body['token'] ?? ''), (string) ($body['piattaforma'] ?? ''));

        // L'app sa se lato server le push sono attive (altrimenti restano solo le email).
        return $this->json(['pushAttive' => $this->gateway->isEnabled()], Response::HTTP_CREATED);
    }

    #[Route('/api/me/dispositivi', name: 'api_me_dispositivo_rimuovi', methods: ['DELETE'])]
    public function rimuovi(#[CurrentUser] User $user, Request $request): Response
    {
        $this->push->rimuovi($user, (string) ($this->body($request)['token'] ?? ''));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
