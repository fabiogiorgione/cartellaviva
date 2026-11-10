<?php

namespace App\Account\Anagrafica;

use App\Account\Entity\Anagrafica\Address;
use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\Anagrafica\Email;
use App\Account\Entity\Anagrafica\Telefono;
use App\Account\Entity\Geo\GeoComune;
use App\Account\Entity\Geo\GeoCountry;
use Doctrine\ORM\EntityManagerInterface;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Conversione JSON <-> Anagrafica. Le collezioni (indirizzi, telefoni, email,
 * PEC) vengono sostituite per intero a ogni salvataggio. Le etichette di comuni
 * e nazioni sono incluse in lettura per comodità del client.
 */
final class AnagraficaMapper
{
    private const DATE_FIELDS = ['dataDiNascita', 'inItaliaDal', 'dataRilascioDocumentoIdentita', 'dataScadenzaDocumentoIdentita'];
    private const STRING_FIELDS = [
        'nome', 'cognome', 'soprannome', 'titolo', 'sesso', 'statoCivile', 'codiceFiscale',
        'tipoDocumentoIdentita', 'numeroDocumentoIdentita', 'rilasciatoDaDocumentoIdentita',
        'denominazione', 'vat', 'sdi', 'capitaleSociale', 'sitoweb',
    ];
    private const INT_FIELDS = ['nazioneDiNascita', 'comuneDiNascita', 'cittadinanza'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param array<string, mixed> $data */
    public function apply(Anagrafica $a, array $data): void
    {
        if (isset($data['type']) && \is_string($data['type'])) {
            $a->setType($data['type']);
        }
        foreach (self::STRING_FIELDS as $field) {
            if (\array_key_exists($field, $data)) {
                $a->{'set'.ucfirst($field)}($this->string($data[$field]));
            }
        }
        foreach (self::INT_FIELDS as $field) {
            if (\array_key_exists($field, $data)) {
                $a->{'set'.ucfirst($field)}($this->int($data[$field]));
            }
        }
        foreach (self::DATE_FIELDS as $field) {
            if (\array_key_exists($field, $data)) {
                $a->{'set'.ucfirst($field)}($this->date($data[$field]));
            }
        }
        if (\array_key_exists('codiceFiscaleManuale', $data)) {
            $a->setCodiceFiscaleManuale((bool) $data['codiceFiscaleManuale']);
        }

        if (isset($data['addresses']) && \is_array($data['addresses'])) {
            foreach ($a->getAddresses()->toArray() as $old) {
                $a->removeAddress($old);
            }
            foreach ($data['addresses'] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $address = new Address();
                $address->setType((string) ($row['type'] ?? Address::TYPE_RESIDENZA));
                $address->setNazione($this->int($row['nazione'] ?? null));
                $address->setIndirizzo($this->string($row['indirizzo'] ?? null));
                $address->setComune($this->int($row['comune'] ?? null));
                $address->setComuneEstero($this->string($row['comuneEstero'] ?? null));
                $address->setCap($this->string($row['cap'] ?? null));
                $provincia = $this->string($row['provincia'] ?? null);
                if (null === $provincia && null !== $address->getComune()) {
                    $provincia = $this->em->find(GeoComune::class, $address->getComune())?->getProvincialCode();
                }
                $address->setProvincia($provincia);
                $a->addAddress($address);
            }
        }

        if (isset($data['telefono']) && \is_array($data['telefono'])) {
            foreach ($a->getTelefono()->toArray() as $old) {
                $a->removeTelefono($old);
            }
            foreach ($data['telefono'] as $row) {
                if (\is_array($row) && null !== ($number = $this->string($row['phoneNumber'] ?? null))) {
                    $a->addTelefono(new Telefono($this->normalizePhone($number), $this->string($row['nota'] ?? null), (bool) ($row['main'] ?? false)));
                }
            }
        }

        foreach (['email' => 'Email', 'pec' => 'Pec'] as $field => $suffix) {
            if (!isset($data[$field]) || !\is_array($data[$field])) {
                continue;
            }
            foreach ($a->{'get'.$suffix}()->toArray() as $old) {
                $a->{'remove'.$suffix}($old);
            }
            foreach ($data[$field] as $row) {
                if (\is_array($row) && null !== ($value = $this->string($row['email'] ?? null))) {
                    $a->{'add'.$suffix}(new Email($value, $this->string($row['nota'] ?? null), (bool) ($row['main'] ?? false)));
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(Anagrafica $a): array
    {
        $data = ['id' => (string) $a->getId(), 'type' => $a->getType()];
        foreach (self::STRING_FIELDS as $field) {
            $data[$field] = $a->{'get'.ucfirst($field)}();
        }
        foreach (self::INT_FIELDS as $field) {
            $data[$field] = $a->{'get'.ucfirst($field)}();
        }
        foreach (self::DATE_FIELDS as $field) {
            $data[$field] = $a->{'get'.ucfirst($field)}()?->format('Y-m-d');
        }
        $data['codiceFiscaleManuale'] = (bool) $a->isCodiceFiscaleManuale();
        $data['nazioneDiNascitaLabel'] = $this->countryLabel($a->getNazioneDiNascita());
        $data['comuneDiNascitaLabel'] = $this->comuneLabel($a->getComuneDiNascita());
        $data['cittadinanzaLabel'] = $this->countryLabel($a->getCittadinanza());

        $data['addresses'] = array_values(array_map(fn (Address $ad) => [
            'type' => $ad->getType(),
            'nazione' => $ad->getNazione(),
            'nazioneLabel' => $this->countryLabel($ad->getNazione()),
            'indirizzo' => $ad->getIndirizzo(),
            'comune' => $ad->getComune(),
            'comuneLabel' => $this->comuneLabel($ad->getComune()),
            'comuneEstero' => $ad->getComuneEstero(),
            'provincia' => $ad->getProvincia(),
            'cap' => $ad->getCap(),
        ], $a->getAddresses()->toArray()));
        $data['telefono'] = array_values(array_map(static fn (Telefono $t) => ['phoneNumber' => $t->getPhoneNumber(), 'nota' => $t->getNota(), 'main' => (bool) $t->isMain()], $a->getTelefono()->toArray()));
        $data['email'] = array_values(array_map(static fn (Email $e) => ['email' => $e->getEmail(), 'nota' => $e->getNota(), 'main' => (bool) $e->isMain()], $a->getEmail()->toArray()));
        $data['pec'] = array_values(array_map(static fn (Email $e) => ['email' => $e->getEmail(), 'nota' => $e->getNota(), 'main' => (bool) $e->isMain()], $a->getPec()->toArray()));

        return $data;
    }

    private function normalizePhone(string $raw): string
    {
        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($raw, 'IT');
            if ($util->isValidNumber($number)) {
                return $util->format($number, PhoneNumberFormat::E164);
            }
        } catch (\Throwable) {
        }

        return $raw; // lasciato com'è: lo segnalerà il validatore
    }

    private function countryLabel(?int $istat): ?string
    {
        return null === $istat ? null : $this->em->find(GeoCountry::class, $istat)?->getNameIt();
    }

    private function comuneLabel(?int $id): ?string
    {
        return null === $id ? null : $this->em->find(GeoComune::class, $id)?->getLabel();
    }

    private function string(mixed $value): ?string
    {
        if (null === $value || \is_array($value)) {
            return null;
        }
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return false === $date ? null : $date;
    }
}
