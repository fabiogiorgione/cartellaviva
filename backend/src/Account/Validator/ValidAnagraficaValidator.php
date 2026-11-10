<?php

namespace App\Account\Validator;

use App\Account\Anagrafica\CodiceFiscaleService;
use App\Account\Anagrafica\PartitaIvaService;
use App\Account\Entity\Anagrafica\Address;
use App\Account\Entity\Anagrafica\Anagrafica;
use App\Account\Entity\Geo\GeoComune;
use App\Account\Entity\Geo\GeoCountry;
use App\Account\Repository\AnagraficaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Verifica della coerenza dell'anagrafica, con gli
 * stessi messaggi. Regole attive per gruppo:
 *
 * - privato: nome, cognome, sesso, data di nascita (maggiorenne), nazione e
 *   comune di nascita, codice fiscale (formale + coerenza + unicità), "in Italia dal"
 *   per i nati all'estero, residenza, documento d'identità coerente;
 * - azienda: denominazione, legale rappresentante, partita IVA (checksum + unicità),
 *   sede legale.
 * - sempre: email/PEC valide (constraint sulle entità figlie), date coerenti.
 */
final class ValidAnagraficaValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CodiceFiscaleService $codiceFiscaleService,
        private readonly AnagraficaRepository $anagrafiche,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidAnagrafica) {
            throw new UnexpectedTypeException($constraint, ValidAnagrafica::class);
        }
        if (!$value instanceof Anagrafica) {
            return;
        }

        $group = $this->context->getGroup();
        if (Anagrafica::GROUP_MEMBRO_FAMIGLIA === $group) {
            $this->validateMembroFamiglia($value);
        } elseif (Anagrafica::TYPEAZIENDA === $group && $value->isAzienda()) {
            $this->validateAzienda($value);
        } elseif (Anagrafica::TYPEPRIVATO === $group && !$value->isAzienda()) {
            $this->validatePrivato($value);
        }
    }

    private function validatePrivato(Anagrafica $a): void
    {
        $this->required($a->getNome(), 'nome', 'Il campo nome è obbligatorio');
        $this->required($a->getCognome(), 'cognome', 'Il campo cognome è obbligatorio');
        $this->required($a->getSesso(), 'sesso', 'Il campo sesso è obbligatorio');
        $this->validateDataDiNascita($a);
        $this->validateNazioneDiNascitaAndComuneDiNascita($a);
        $this->validateCittadinanza($a);
        $this->validateCodiceFiscale($a);
        $this->validateInItaliaDal($a);
        $this->validateDocumentoIdentita($a);

        if (null === $a->getResidenza()) {
            $this->violation('addresses', 'La residenza è obbligatoria');
        }
    }

    /**
     * Membro della famiglia senza account (anche minorenne): obbligatorio solo il
     * nome; il codice fiscale è facoltativo ma, se presente, deve essere valido e
     * coerente con i dati inseriti (se sono sufficienti a verificarlo).
     * L'unicità del CF nel tenant la controlla ValidMembroFamigliaValidator.
     */
    private function validateMembroFamiglia(Anagrafica $a): void
    {
        $this->required($a->getNome(), 'nome', 'Il campo nome è obbligatorio');

        if ($a->getDataDiNascita() && $a->getDataDiNascita() > new \DateTimeImmutable('today')) {
            $this->violation('dataDiNascita', 'La data di nascita non può essere nel futuro');
        }
        if (null !== $a->getNazioneDiNascita()) {
            $this->validateNazioneDiNascitaAndComuneDiNascita($a);
        }

        $cf = $a->getCodiceFiscale();
        if (!$cf) {
            return;
        }
        if ($a->isCodiceFiscaleManuale()) {
            if (16 !== \strlen($cf)) {
                $this->violation('codiceFiscale', 'Il codice fiscale non è inserito nella forma corretta');
            }

            return;
        }
        if (!$this->codiceFiscaleService->isFormallyValid($cf)) {
            $this->violation('codiceFiscale', 'Il codice fiscale non è inserito nella forma corretta');

            return;
        }
        $registryCode = $this->codiceFiscaleService->getRegistryCode($a);
        if ($a->getNome() && $a->getCognome() && $a->getDataDiNascita() && $a->getSesso() && $registryCode
            && !$this->codiceFiscaleService->check($a->getNome(), $a->getCognome(), $a->getDataDiNascita(), $a->getSesso(), $registryCode, $cf)) {
            $this->violation('codiceFiscale', 'Il codice fiscale non corrisponde ai dati inseriti');
        }
    }

    private function validateAzienda(Anagrafica $a): void
    {
        $this->required($a->getDenominazione(), 'denominazione', 'Il campo denominazione è obbligatorio');
        $this->required($a->getCognome(), 'cognome', 'Il campo cognome legale rappresentante è obbligatorio');
        $this->required($a->getNome(), 'nome', 'Il campo nome legale rappresentante è obbligatorio');

        if (!$a->getVat()) {
            $this->violation('vat', 'Il campo partita iva è obbligatorio');
        } else {
            $code = PartitaIvaService::validate($a->getVat());
            if (null !== $code) {
                $this->violation('vat', PartitaIvaService::MESSAGES[$code]);
            } elseif (!$this->anagrafiche->isVatUnique($a->getVat(), $a->getId())) {
                $this->violation('vat', 'La Partita Iva è già associata ad un altra anagrafica');
            }
        }

        // Il codice fiscale di una società è numerico (spesso = P.IVA): si controlla solo se presente.
        if ($a->getCodiceFiscale() && !preg_match('/^([0-9]{11}|[A-Z0-9]{16})$/', $a->getCodiceFiscale())) {
            $this->violation('codiceFiscale', 'Il codice fiscale non è inserito nella forma corretta');
        }

        if (null === $a->getResidenza()) {
            $this->violation('addresses', 'La sede legale è obbligatoria');
        }
    }

    private function validateDataDiNascita(Anagrafica $a): void
    {
        $data = $a->getDataDiNascita();
        if (null === $data) {
            $this->violation('dataDiNascita', 'Il campo data di nascita è obbligatorio');

            return;
        }
        $today = new \DateTimeImmutable('today');
        if ($data > $today) {
            $this->violation('dataDiNascita', 'La data di nascita non può essere nel futuro');
        } elseif ($today->diff($data)->y < 18) {
            // In CartellaViva i minori non hanno un account (i loro dati li gestisce il genitore).
            $this->violation('dataDiNascita', 'E\' possibile censire solamente maggiorenni');
        }
    }

    private function validateNazioneDiNascitaAndComuneDiNascita(Anagrafica $a): void
    {
        $nazione = $a->getNazioneDiNascita();
        if (null === $nazione) {
            $this->violation('nazioneDiNascita', 'Il campo Nazione di nascita è obbligatorio');

            return;
        }
        if (null === $this->em->find(GeoCountry::class, $nazione)) {
            $this->violation('nazioneDiNascita', 'Nazione di nascita non valida');

            return;
        }
        if (GeoCountry::ITALIA === $nazione) {
            if (!$a->getComuneDiNascita()) {
                $this->violation('comuneDiNascita', 'Il comune di nascita è obbligatorio');
            } elseif (null === $this->em->find(GeoComune::class, $a->getComuneDiNascita())) {
                $this->violation('comuneDiNascita', 'Comune di nascita non valido');
            }
        }
    }

    private function validateCittadinanza(Anagrafica $a): void
    {
        if (null !== $a->getCittadinanza() && null === $this->em->find(GeoCountry::class, $a->getCittadinanza())) {
            $this->violation('cittadinanza', 'Cittadinanza non valida');
        }
    }

    private function validateCodiceFiscale(Anagrafica $a): void
    {
        $cf = $a->getCodiceFiscale();
        if (!$cf) {
            $this->violation('codiceFiscale', 'Il campo codice fiscale è obbligatorio');

            return;
        }

        if ($a->isCodiceFiscaleManuale()) {
            if (16 !== \strlen($cf)) {
                $this->violation('codiceFiscale', 'Il codice fiscale non è inserito nella forma corretta');
            }
        } elseif (!$this->codiceFiscaleService->isFormallyValid($cf)) {
            $this->violation('codiceFiscale', 'Il codice fiscale non è inserito nella forma corretta');
        } else {
            $registryCode = $this->codiceFiscaleService->getRegistryCode($a);
            if ($a->getNome() && $a->getCognome() && $a->getDataDiNascita() && $a->getSesso() && $registryCode) {
                if (!$this->codiceFiscaleService->check($a->getNome(), $a->getCognome(), $a->getDataDiNascita(), $a->getSesso(), $registryCode, $cf)) {
                    $this->violation('codiceFiscale', 'Il codice fiscale non corrisponde ai dati inseriti');
                }
            } else {
                $this->violation('codiceFiscale', 'I dati inseriti non sono sufficienti per verificare il codice fiscale');
            }
        }

        if (!$this->anagrafiche->isCodiceFiscaleUnique($cf, $a->getId())) {
            $this->violation('codiceFiscale', 'Il codice fiscale è già associato ad un\'altra anagrafica');
        }
    }

    private function validateInItaliaDal(Anagrafica $a): void
    {
        if (GeoCountry::ITALIA === $a->getNazioneDiNascita() || null === $a->getInItaliaDal()) {
            return;
        }
        if ($a->getInItaliaDal() > new \DateTimeImmutable('today')) {
            $this->violation('inItaliaDal', 'La data non può essere successiva ad oggi');
        }
        if ($a->getDataDiNascita() && $a->getInItaliaDal() < $a->getDataDiNascita()) {
            $this->violation('inItaliaDal', 'La data non può essere antecedente alla data di nascita');
        }
    }

    private function validateDocumentoIdentita(Anagrafica $a): void
    {
        $hasAny = $a->getTipoDocumentoIdentita() || $a->getNumeroDocumentoIdentita() || $a->getDataScadenzaDocumentoIdentita();
        if (!$hasAny) {
            return; // facoltativo, ma se compilato deve essere completo e coerente
        }
        $this->required($a->getTipoDocumentoIdentita(), 'tipoDocumentoIdentita', 'Il tipo di documento è obbligatorio');
        $this->required($a->getNumeroDocumentoIdentita(), 'numeroDocumentoIdentita', 'Il numero del documento è obbligatorio');

        $rilascio = $a->getDataRilascioDocumentoIdentita();
        $scadenza = $a->getDataScadenzaDocumentoIdentita();
        if ($rilascio && $rilascio > new \DateTimeImmutable('today')) {
            $this->violation('dataRilascioDocumentoIdentita', 'La data di rilascio non può essere nel futuro');
        }
        if ($rilascio && $scadenza && $scadenza <= $rilascio) {
            $this->violation('dataScadenzaDocumentoIdentita', 'La data di scadenza deve essere successiva al rilascio');
        }
    }

    private function required(mixed $value, string $path, string $message): void
    {
        if (null === $value || '' === $value) {
            $this->violation($path, $message);
        }
    }

    private function violation(string $path, string $message): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
