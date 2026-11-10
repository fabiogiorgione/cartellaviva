<?php

namespace App\Account\Entity\Geo;

use App\Account\Repository\GeoCountryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stati esteri e Italia (dataset devnix/belfiore-code).
 * La chiave è il codice ISTAT dello stato (Italia = 100), lo stesso valore salvato
 * in Anagrafica::nazioneDiNascita / cittadinanza e in Indirizzo::nazione.
 */
#[ORM\Entity(repositoryClass: GeoCountryRepository::class, readOnly: true)]
#[ORM\Table(name: 'geo_country')]
class GeoCountry
{
    public const ITALIA = 100;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        private int $istatId,

        #[ORM\Column(length: 120)]
        private string $nameIt,

        #[ORM\Column(length: 120, nullable: true)]
        private ?string $nameEn,

        /** Codice Belfiore (Zxxx) usato nel codice fiscale dei nati all'estero. */
        #[ORM\Column(length: 4, nullable: true)]
        private ?string $registryCode,

        #[ORM\Column(length: 10, nullable: true)]
        private ?string $isoAlpha2,

        #[ORM\Column(length: 60, nullable: true)]
        private ?string $continentNameIt,
    ) {
    }

    public function getIstatId(): int
    {
        return $this->istatId;
    }

    public function getNameIt(): string
    {
        return $this->nameIt;
    }

    public function getNameEn(): ?string
    {
        return $this->nameEn;
    }

    public function getRegistryCode(): ?string
    {
        return $this->registryCode;
    }

    public function getIsoAlpha2(): ?string
    {
        return $this->isoAlpha2;
    }

    public function getContinentNameIt(): ?string
    {
        return $this->continentNameIt;
    }
}
