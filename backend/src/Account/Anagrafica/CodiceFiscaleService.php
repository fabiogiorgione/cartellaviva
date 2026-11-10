<?php

namespace App\Account\Anagrafica;

use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\Geo\GeoComune;
use App\Account\Entity\Geo\GeoCountry;
use CodiceFiscale\Calculator;
use CodiceFiscale\Checker;
use CodiceFiscale\Subject;
use CodiceFiscale\Validator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Verifica del codice fiscale che riguarda il
 * codice fiscale: validazione formale, verifica di coerenza con i dati
 * anagrafici (omocodia inclusa) e calcolo. Usa davidepastore/codice-fiscale,
 * e il codice Belfiore di comune o stato di nascita.
 */
final class CodiceFiscaleService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function isFormallyValid(string $codiceFiscale): bool
    {
        return (new Validator(strtoupper($codiceFiscale)))->isFormallyValid();
    }

    /**
     * Codice Belfiore del luogo di nascita: il comune se nato in Italia,
     * altrimenti lo stato estero.
     */
    public function getRegistryCode(Anagrafica $anagrafica): ?string
    {
        $nazione = $anagrafica->getNazioneDiNascita();
        if (null === $nazione) {
            return null;
        }
        if (GeoCountry::ITALIA === $nazione) {
            $comune = $anagrafica->getComuneDiNascita() ? $this->em->find(GeoComune::class, $anagrafica->getComuneDiNascita()) : null;

            return $comune?->getRegistryCode();
        }

        return $this->em->find(GeoCountry::class, $nazione)?->getRegistryCode();
    }

    public function check(string $nome, string $cognome, \DateTimeInterface $dataDiNascita, string $sesso, string $registryCode, string $codiceFiscale): bool
    {
        $checker = new Checker($this->subject($nome, $cognome, $dataDiNascita, $sesso, $registryCode), [
            'codiceFiscaleToCheck' => strtoupper($codiceFiscale),
            'omocodiaLevel' => Checker::ALL_OMOCODIA_LEVELS,
        ]);

        return $checker->check();
    }

    public function calculate(string $nome, string $cognome, \DateTimeInterface $dataDiNascita, string $sesso, string $registryCode): string
    {
        return (new Calculator($this->subject($nome, $cognome, $dataDiNascita, $sesso, $registryCode)))->calculate();
    }

    private function subject(string $nome, string $cognome, \DateTimeInterface $dataDiNascita, string $sesso, string $registryCode): Subject
    {
        return new Subject([
            'name' => $nome,
            'surname' => $cognome,
            'birthDate' => $dataDiNascita->format('Y-m-d'),
            'gender' => strtoupper($sesso),
            'belfioreCode' => $registryCode,
        ]);
    }
}
