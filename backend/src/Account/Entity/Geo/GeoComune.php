<?php

namespace App\Account\Entity\Geo;

use App\Account\Repository\GeoComuneRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Comuni italiani, attivi e soppressi (dataset devnix/belfiore-code, come
 * La chiave è l'"indice comune" del dataset: è il valore
 * salvato in Anagrafica::comuneDiNascita e Indirizzo::comune, e distingue anche le
 * diverse "versioni" storiche dello stesso comune.
 */
#[ORM\Entity(repositoryClass: GeoComuneRepository::class, readOnly: true)]
#[ORM\Table(name: 'geo_comune')]
#[ORM\Index(name: 'idx_geo_comune_name', columns: ['name_it'])]
#[ORM\Index(name: 'idx_geo_comune_registry_code', columns: ['registry_code'])]
class GeoComune
{
    public const STATUS_ACTIVE = 'active';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $id,

        #[ORM\Column(length: 6)]
        private string $istatId,

        /** Codice catastale / Belfiore (es. F205 per Milano). */
        #[ORM\Column(length: 4)]
        private string $registryCode,

        #[ORM\Column(length: 120)]
        private string $nameIt,

        #[ORM\Column(length: 10, nullable: true)]
        private ?string $provincialCode,

        #[ORM\Column(length: 20)]
        private string $status,

        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $institutionDate,

        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $endDate,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIstatId(): string
    {
        return $this->istatId;
    }

    public function getRegistryCode(): string
    {
        return $this->registryCode;
    }

    public function getNameIt(): string
    {
        return $this->nameIt;
    }

    public function getProvincialCode(): ?string
    {
        return $this->provincialCode;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function getInstitutionDate(): \DateTimeImmutable
    {
        return $this->institutionDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    /** Etichetta: "Nome (PR)" e, se soppresso, "fino al mm/aaaa". */
    public function getLabel(): string
    {
        $label = ucwords(mb_strtolower($this->nameIt));
        if ($this->provincialCode) {
            $label .= ' ('.$this->provincialCode.')';
        }
        if (!$this->isActive()) {
            $label .= ' - fino al '.$this->endDate->format('m/Y');
        }

        return $label;
    }
}
