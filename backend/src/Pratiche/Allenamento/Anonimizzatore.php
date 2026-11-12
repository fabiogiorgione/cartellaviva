<?php

namespace App\Pratiche\Allenamento;

use App\Pratiche\TestoVoci;
use App\Pratiche\ValoriVoce;

/**
 * Anonimizza un esempio (testo del documento + risposta giusta) prima che
 * esca dal server: stesse sostituzioni nel testo e nei valori, così l'esempio
 * resta coerente (l'importo della risposta è quello scritto nel testo).
 *
 * Si sostituiscono con valori finti dello stesso formato: nomi e cognomi
 * delle persone indicate, codici fiscali, IBAN (con il controllo mod 97
 * valido), targhe, email, numeri di telefono, numeri lunghi (codici cliente,
 * polizze, avvisi) e importi (moltiplicati per un fattore casuale, tenendo
 * l'ordine di grandezza). Restano: date, nomi di aziende ed enti, il resto del
 * testo. Non è perfetto (un indirizzo o un nome non indicato restano): il
 * file va guardato prima di farlo uscire.
 */
final class Anonimizzatore
{
    private const NOMI = ['Luca', 'Giulia', 'Marco', 'Sara', 'Paolo', 'Elena', 'Andrea', 'Chiara', 'Davide', 'Francesca', 'Matteo', 'Laura'];
    private const COGNOMI = ['Bianchi', 'Ferrari', 'Esposito', 'Romano', 'Colombo', 'Ricci', 'Marino', 'Greco', 'Bruno', 'Gallo', 'Conti', 'Costa'];
    // Non dentro una data "12.03.2025": dopo l'importo non viene un altro ".cifra".
    private const IMPORTO = '(?<![\d.,])(\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2}|\d+\.\d{2})(?![\d,]|\.\d)';

    /** @var array<string, string> originale (minuscolo per le parole) => finto */
    private array $mappa = [];

    /** @var array<int, int> centesimi originali => centesimi finti */
    private array $importi = [];

    private float $fattore;

    /** @param list<string> $nomiPersone nomi e cognomi da togliere (es. "Mario Rossi") */
    public function __construct(private readonly array $nomiPersone, ?int $seme = null)
    {
        if (null !== $seme) {
            mt_srand($seme);
        }
        $this->fattore = mt_rand(60, 140) / 100;
        $parole = [];
        foreach ($nomiPersone as $nome) {
            foreach (preg_split('/\s+/u', trim($nome)) ?: [] as $p) {
                if (mb_strlen($p) >= 3) {
                    $parole[mb_strtolower($p)] = true;
                }
            }
        }
        $i = 0;
        foreach (array_keys($parole) as $p) {
            $this->mappa[$p] = 0 === $i % 2 ? self::NOMI[$i % \count(self::NOMI)] : self::COGNOMI[$i % \count(self::COGNOMI)];
            ++$i;
        }
    }

    /**
     * Nomi che seguono un'etichetta (Intestatario, Sig., Nominativo, Cognome e
     * nome, Beneficiario, Ordinante, Dichiarante, Contribuente...): si
     * aggiungono a quelli da sostituire, anche se non sono della famiglia.
     */
    private function nomiDalleEtichette(string $testo): void
    {
        $etichette = '(?:intestatari[oa]|cointestatari[oa]|sig\.?\s?(?:ra|ra\.)?|signor[ae]?|nominativo|cognome\s+e\s+nome|nome\s+e\s+cognome|beneficiari[oa]|ordinante|dichiarante|contribuente|assicurat[oa]|paziente|alunn[oa]|studente|titolare|destinatari[oa]|richiedente|cliente)';
        $nome = "[A-ZÀ-Ý][\\p{L}']+";
        preg_match_all('~(?<![\p{L}])'.$etichette.'\s*[:\-]?\s*((?:'.$nome.'[ \t]+){1,3}'.$nome.')~iu', $testo, $m);
        foreach ($m[1] as $trovato) {
            foreach (preg_split('/\s+/u', $trovato) ?: [] as $p) {
                $k = mb_strtolower($p);
                if (mb_strlen($p) >= 3 && !isset($this->mappa[$k]) && 1 === preg_match('/^\p{Lu}/u', $p)) {
                    $i = \count($this->mappa);
                    $this->mappa[$k] = 0 === $i % 2 ? self::NOMI[$i % \count(self::NOMI)] : self::COGNOMI[$i % \count(self::COGNOMI)];
                }
            }
        }
    }

    public function testo(string $testo): string
    {
        $this->nomiDalleEtichette($testo);
        // Prima i codici (contengono cifre e lettere), poi i numeri, infine i nomi.
        $testo = (string) preg_replace_callback('~\b[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]\b~i', fn ($m) => $this->finto('cf', strtoupper($m[0]), fn () => self::codiceFiscale()), $testo);
        $testo = (string) preg_replace_callback('~\bIT\s?\d{2}\s?[A-Z](?:\s?\d){10}(?:\s?[0-9A-Z]){12}\b~i', fn ($m) => $this->finto('iban', strtoupper((string) preg_replace('/\s+/', '', $m[0])), fn () => self::iban()), $testo);
        $testo = (string) preg_replace_callback('~\b[A-Z]{2}\s?\d{3}\s?[A-Z]{2}\b~', fn ($m) => $this->finto('targa', (string) preg_replace('/\s+/', '', $m[0]), fn () => self::targa()), $testo);
        $testo = (string) preg_replace_callback('~[\w.+-]+@[\w-]+\.[\w.]+~u', fn ($m) => $this->finto('email', mb_strtolower($m[0]), fn () => 'persona'.mt_rand(10, 99).'@esempio.it'), $testo);
        $testo = (string) preg_replace_callback('~(?<!\d)(?:\+39\s?)?3\d{2}[\s.]?\d{3}[\s.]?\d{3,4}(?!\d)~', fn ($m) => $this->finto('tel', (string) preg_replace('/\D/', '', $m[0]), fn () => '3'.mt_rand(10, 99).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999)), $testo);
        $testo = (string) preg_replace_callback('~(?<![\w.,])\d{8,}(?![\w.,])~u', fn ($m) => $this->finto('num', $m[0], fn () => self::cifre(\strlen($m[0]))), $testo);
        $testo = (string) preg_replace_callback('~'.self::IMPORTO.'~', fn ($m) => $this->importo($m[1]), $testo);

        return $this->nomi($testo);
    }

    /**
     * Valori di una voce (risposta giusta) con le stesse sostituzioni del testo.
     *
     * @param array<string, string> $valori
     *
     * @return array<string, string>
     */
    public function valori(array $valori): array
    {
        $out = [];
        foreach ($valori as $k => $v) {
            $out[$k] = match (true) {
                'importo' === $k || str_contains($k, 'giacenza') || str_contains($k, 'saldo') || str_contains($k, 'mutuo') => null !== ($c = ValoriVoce::centesimi($v)) ? ValoriVoce::formatta($this->centesimi($c)) : $v,
                'iban' === $k => $this->finto('iban', strtoupper($v), fn () => self::iban()),
                'targa' === $k => $this->finto('targa', strtoupper($v), fn () => self::targa()),
                default => $this->nomi($v),
            };
        }

        return $out;
    }

    public function nomePersona(string $nome): string
    {
        return $this->nomi($nome);
    }

    private function nomi(string $testo): string
    {
        foreach ($this->mappa as $vero => $finto) {
            $testo = (string) preg_replace('~(?<![\p{L}])'.preg_quote($vero, '~').'(?![\p{L}])~iu', $finto, $testo);
        }

        return $testo;
    }

    /** "3.210,55" → stesso formato, importo finto (lo stesso ovunque compaia). */
    private function importo(string $grezzo): string
    {
        $c = ValoriVoce::centesimi($grezzo);
        if (null === $c) {
            return $grezzo;
        }
        $n = $this->centesimi($c);
        $intero = intdiv($n, 100);
        $dec = sprintf('%02d', $n % 100);

        return match (true) {
            str_contains($grezzo, ',') && str_contains($grezzo, '.') => number_format($intero, 0, '', '.').','.$dec,
            str_contains($grezzo, ',') => $intero.','.$dec,
            default => $intero.'.'.$dec,
        };
    }

    private function centesimi(int $c): int
    {
        return $this->importi[$c] ??= max(1, (int) round($c * $this->fattore));
    }

    /** @param callable(): string $nuovo */
    private function finto(string $tipo, string $vero, callable $nuovo): string
    {
        return $this->mappa[$tipo.'|'.$vero] ??= $nuovo();
    }

    private static function cifre(int $n): string
    {
        $s = (string) mt_rand(1, 9);
        while (\strlen($s) < $n) {
            $s .= (string) mt_rand(0, 9);
        }

        return $s;
    }

    private static function lettere(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; ++$i) {
            $s .= \chr(mt_rand(65, 90));
        }

        return $s;
    }

    private static function codiceFiscale(): string
    {
        return self::lettere(6).self::cifre(2).self::lettere(1).sprintf('%02d', mt_rand(1, 28)).self::lettere(1).self::cifre(3).self::lettere(1);
    }

    private static function targa(): string
    {
        return self::lettere(2).self::cifre(3).self::lettere(2);
    }

    /** IBAN italiano finto ma con le cifre di controllo giuste (mod 97), come quelli veri. */
    private static function iban(): string
    {
        $bban = self::lettere(1).self::cifre(10).self::cifre(12);
        $numero = '';
        foreach (str_split($bban.'IT00') as $c) {
            $numero .= ctype_alpha($c) ? (string) (\ord($c) - 55) : $c;
        }
        $resto = 0;
        foreach (str_split($numero, 7) as $pezzo) {
            $resto = (int) (((string) $resto).$pezzo) % 97;
        }

        return sprintf('IT%02d', 98 - $resto).$bban;
    }
}
