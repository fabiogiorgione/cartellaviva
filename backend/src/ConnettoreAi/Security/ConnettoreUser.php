<?php

namespace App\ConnettoreAi\Security;

use App\ConnettoreAi\Entity\ConnettoreAi;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Identità di un connettore autenticato con il suo token: vale solo sul
 * firewall "ai_connector", non è un utente e non ha permessi nella famiglia.
 */
final class ConnettoreUser implements UserInterface
{
    public const RUOLO = 'ROLE_CONNETTORE_AI';

    public function __construct(public readonly ConnettoreAi $connettore)
    {
    }

    public function getRoles(): array
    {
        return [self::RUOLO];
    }

    public function getUserIdentifier(): string
    {
        return 'connettore:'.$this->connettore->getId()->toRfc4122();
    }

    public function eraseCredentials(): void
    {
    }
}
