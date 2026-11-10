<?php

namespace App\Account\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public readonly string $email = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 10, max: 4096)]
        #[Assert\NotCompromisedPassword(skipOnError: true)]
        public readonly string $password = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public readonly string $firstName = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public readonly string $lastName = '',

        /** Nome del tenant privato creato alla registrazione (default: "Famiglia <cognome>"). */
        #[Assert\Length(max: 150)]
        public readonly ?string $tenantName = null,
    ) {
    }
}
