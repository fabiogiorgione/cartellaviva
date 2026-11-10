<?php

namespace App\Account\Entity\Anagrafica;

use Doctrine\ORM\Mapping as ORM;
use Misd\PhoneNumberBundle\Validator\Constraints\PhoneNumber as AssertPhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Telefono di un'anagrafica.
 * Il numero è salvato in formato E.164 (+393331234567); la validazione usa
 * libphonenumber con regione predefinita IT.
 */
#[ORM\Entity]
#[ORM\Table(name: 'anagrafica_telefono')]
class Telefono
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Anagrafica::class, inversedBy: 'telefono')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Anagrafica $anagrafica = null;

    #[ORM\Column(length: 35)]
    #[Assert\NotBlank(groups: ['Default', Anagrafica::TYPEPRIVATO, Anagrafica::TYPEAZIENDA])]
    #[AssertPhoneNumber(defaultRegion: 'IT', message: 'Numero di telefono non valido', groups: ['Default', Anagrafica::TYPEPRIVATO, Anagrafica::TYPEAZIENDA])]
    private string $phoneNumber;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $nota = null;

    #[ORM\Column(nullable: true)]
    private ?bool $main = null;

    public function __construct(string $phoneNumber, ?string $nota = null, ?bool $main = null)
    {
        $this->phoneNumber = $phoneNumber;
        $this->nota = $nota;
        $this->main = $main;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnagrafica(): ?Anagrafica
    {
        return $this->anagrafica;
    }

    public function setAnagrafica(?Anagrafica $anagrafica): void
    {
        $this->anagrafica = $anagrafica;
    }

    public function getPhoneNumber(): string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(string $phoneNumber): void
    {
        $this->phoneNumber = $phoneNumber;
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
