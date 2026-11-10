<?php

namespace App\Account\Entity\Anagrafica;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Indirizzo email di un'anagrafica, usato sia per "email" sia per "pec"
 * (tabella field_email).
 */
#[ORM\Entity]
#[ORM\Table(name: 'field_email')]
#[ORM\Index(name: 'idx_field_email_email', columns: ['email'])]
class Email
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(groups: ['Default', Anagrafica::TYPEPRIVATO, Anagrafica::TYPEAZIENDA])]
    #[Assert\Email(message: 'Il campo email deve essere una mail valida', groups: ['Default', Anagrafica::TYPEPRIVATO, Anagrafica::TYPEAZIENDA])]
    private string $email;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $nota = null;

    #[ORM\Column(nullable: true)]
    private ?bool $main = null;

    public function __construct(string $email, ?string $nota = null, ?bool $main = null)
    {
        $this->email = mb_strtolower(trim($email));
        $this->nota = $nota;
        $this->main = $main;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function isMain(): ?bool
    {
        return $this->main;
    }
}
