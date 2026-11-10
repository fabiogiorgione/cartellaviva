<?php

namespace App\Tenancy\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Con utente autenticato basta il token; altrimenti servono i dati per creare
 * l'account (l'email è quella dell'invito, non scelta dal client).
 */
final class AcceptInvitationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public readonly string $token = '',

        #[Assert\NotBlank(groups: ['registration'])]
        #[Assert\Length(max: 100)]
        public readonly ?string $firstName = null,

        #[Assert\NotBlank(groups: ['registration'])]
        #[Assert\Length(max: 100)]
        public readonly ?string $lastName = null,

        #[Assert\NotBlank(groups: ['registration'])]
        #[Assert\Length(min: 10, max: 4096, groups: ['registration'])]
        #[Assert\NotCompromisedPassword(skipOnError: true, groups: ['registration'])]
        public readonly ?string $password = null,
    ) {
    }
}
