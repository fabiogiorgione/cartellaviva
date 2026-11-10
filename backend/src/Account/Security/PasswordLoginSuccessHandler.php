<?php

namespace App\Account\Security;

use App\Account\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Success handler di json_login: al posto di emettere subito il JWT (handler
 * di Lexik) passa da LoginResponder, che chiede il secondo fattore se attivo.
 */
final class PasswordLoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private readonly LoginResponder $responder)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        \assert($user instanceof User);

        return $this->responder->respond($user, 'password');
    }
}
