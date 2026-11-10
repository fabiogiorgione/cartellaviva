<?php

namespace App\Ente;

use App\Ente\Enum\EnteTipo;
use App\Shared\Errore\DatiNonValidi;

/**
 * Controlli formali sui dati dell'ente (i controlli sostanziali, come la
 * presenza nell'anagrafe delle scuole o nel RUNTS, li fa il portale).
 */
final class EnteDatiValidator
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array{tipo: EnteTipo, denominazione: string, codiceFiscale: string, codiceMeccanografico: ?string, numeroRunts: ?string, emailIstituzionale: string, sedeLegale: ?string}
     */
    public function validate(array $data): array
    {
        $tipo = EnteTipo::tryFrom((string) ($data['tipo'] ?? '')) ?? throw new DatiNonValidi('tipo: per ora si registrano solo scuole e associazioni.');
        $denominazione = trim((string) ($data['denominazione'] ?? ''));
        if ('' === $denominazione || mb_strlen($denominazione) > 150) {
            throw new DatiNonValidi('denominazione: obbligatoria (massimo 150 caratteri).');
        }
        $cf = strtoupper(preg_replace('/\s+/', '', (string) ($data['codiceFiscale'] ?? '')) ?? '');
        if (!self::codiceFiscaleValido($cf)) {
            throw new DatiNonValidi('codiceFiscale: non valido (11 cifre per gli enti).');
        }
        $sede = trim((string) ($data['sedeLegale'] ?? '')) ?: null;

        $codiceMecc = null;
        $runts = null;
        if (EnteTipo::SCUOLA === $tipo) {
            $codiceMecc = strtoupper(trim((string) ($data['codiceMeccanografico'] ?? '')));
            if (1 !== preg_match('/^[A-Z]{2}[A-Z0-9]{8}$/', $codiceMecc)) {
                throw new DatiNonValidi('codiceMeccanografico: 10 caratteri, es. RMEE000000.');
            }
            // L'email istituzionale si ricava dal codice: non la sceglie chi si registra.
            $email = strtolower($codiceMecc).'@istruzione.it';
        } else {
            $runts = trim((string) ($data['numeroRunts'] ?? ''));
            if ('' === $runts || mb_strlen($runts) > 30) {
                throw new DatiNonValidi('numeroRunts: obbligatorio per le associazioni.');
            }
            $email = strtolower(trim((string) ($data['emailIstituzionale'] ?? '')));
            if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                throw new DatiNonValidi('emailIstituzionale: indica l\'email o la PEC registrata nel RUNTS.');
            }
        }

        return [
            'tipo' => $tipo,
            'denominazione' => $denominazione,
            'codiceFiscale' => $cf,
            'codiceMeccanografico' => $codiceMecc,
            'numeroRunts' => $runts,
            'emailIstituzionale' => $email,
            'sedeLegale' => null !== $sede ? mb_substr($sede, 0, 255) : null,
        ];
    }

    /** Codice fiscale numerico degli enti (11 cifre con carattere di controllo, come la partita IVA). */
    public static function codiceFiscaleValido(string $cf): bool
    {
        if (1 !== preg_match('/^\d{11}$/', $cf)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; ++$i) {
            $n = (int) $cf[$i];
            if (1 === $i % 2) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
        }

        return (10 - $sum % 10) % 10 === (int) $cf[10];
    }
}
