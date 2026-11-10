<?php

namespace App\Account\Entity\Anagrafica;

use App\Account\Entity\Geo\GeoCountry;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Indirizzo di un'anagrafica (senza coordinate né aree). Per le aziende "residenza" è la sede legale e
 * "domicilio" la sede operativa.
 */
#[ORM\Entity]
#[ORM\Table(name: 'anagrafica_address')]
class Address
{
    public const TYPE_RESIDENZA = 'residenza';
    public const TYPE_DOMICILIO = 'domicilio';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Anagrafica::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Anagrafica $anagrafica = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TYPE_RESIDENZA, self::TYPE_DOMICILIO], groups: ['Default', Anagrafica::TYPEPRIVATO, Anagrafica::TYPEAZIENDA])]
    private string $type = self::TYPE_RESIDENZA;

    /** Codice ISTAT dello stato (Italia = 100). */
    #[ORM\Column(nullable: true)]
    #[Assert\NotBlank(message: 'La nazione è obbligatoria', groups: ['Default', Anagrafica::TYPEPRIVATO, Anagrafica::TYPEAZIENDA])]
    private ?int $nazione = GeoCountry::ITALIA;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank(message: "L'indirizzo è obbligatorio", groups: ['Default', Anagrafica::TYPEPRIVATO, Anagrafica::TYPEAZIENDA])]
    #[Assert\Length(max: 255)]
    private ?string $indirizzo = null;

    /** Sigla della provincia (es. MI). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $provincia = null;

    /** Indice comune (GeoComune) per indirizzi italiani. */
    #[ORM\Column(nullable: true)]
    private ?int $comune = null;

    /** Località in testo libero per indirizzi esteri. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $comuneEstero = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $cap = null;

    #[Assert\Callback(groups: ['Default', Anagrafica::TYPEPRIVATO, Anagrafica::TYPEAZIENDA])]
    public function validateLocalita(ExecutionContextInterface $context): void
    {
        if (GeoCountry::ITALIA === $this->nazione) {
            if (!$this->comune) {
                $context->buildViolation('Il comune è obbligatorio')->atPath('comune')->addViolation();
            }
            if ($this->cap && !preg_match('/^\d{5}$/', $this->cap)) {
                $context->buildViolation('Il CAP deve essere di 5 cifre')->atPath('cap')->addViolation();
            }
        } elseif (null !== $this->nazione && !$this->comuneEstero) {
            $context->buildViolation('La località è obbligatoria')->atPath('comuneEstero')->addViolation();
        }
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getNazione(): ?int
    {
        return $this->nazione;
    }

    public function setNazione(?int $nazione): void
    {
        $this->nazione = $nazione;
    }

    public function getIndirizzo(): ?string
    {
        return $this->indirizzo;
    }

    public function setIndirizzo(?string $indirizzo): void
    {
        $this->indirizzo = $indirizzo;
    }

    public function getProvincia(): ?string
    {
        return $this->provincia;
    }

    public function setProvincia(?string $provincia): void
    {
        $this->provincia = $provincia ? strtoupper($provincia) : null;
    }

    public function getComune(): ?int
    {
        return $this->comune;
    }

    public function setComune(?int $comune): void
    {
        $this->comune = $comune;
    }

    public function getComuneEstero(): ?string
    {
        return $this->comuneEstero;
    }

    public function setComuneEstero(?string $comuneEstero): void
    {
        $this->comuneEstero = $comuneEstero;
    }

    public function getCap(): ?string
    {
        return $this->cap;
    }

    public function setCap(?string $cap): void
    {
        $this->cap = $cap ? trim($cap) : null;
    }
}
