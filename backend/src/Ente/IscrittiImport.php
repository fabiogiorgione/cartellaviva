<?php

namespace App\Ente;

use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\Referente;
use App\Shared\Errore\DatiNonValidi;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import degli iscritti da CSV (esportato da Excel o dal registro elettronico).
 *
 * Colonne (intestazione obbligatoria, separatore ";" o ","):
 *   nome, cognome, data_nascita (AAAA-MM-GG o GG/MM/AAAA, facoltativa), gruppo,
 *   ref1_nome, ref1_cognome, ref1_email, ref1_relazione, ref2_… (fino a ref4).
 *
 * Il gruppo deve esistere nell'anno in corso ed essere visibile a chi importa.
 * Un iscritto già presente (stesso nome, cognome e data di nascita) viene solo
 * aggiunto al gruppo. Gli inviti NON partono: l'operatore li conferma dopo.
 */
final class IscrittiImport
{
    public const MAX_RIGHE = 2000;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, Gruppo> $gruppiPerNome gruppi visibili dell'anno in corso, chiave nome in minuscolo
     *
     * @return array{righe: list<array<string, mixed>>, valide: int, errori: int, nuovi: int, esistenti: int}
     */
    public function importa(Tenant $ente, string $csv, array $gruppiPerNome, bool $applica): array
    {
        $righe = $this->parse($csv);
        $risultato = ['righe' => [], 'valide' => 0, 'errori' => 0, 'nuovi' => 0, 'esistenti' => 0];
        $visti = [];

        foreach ($righe as $n => $r) {
            $errori = [];
            $nome = trim($r['nome'] ?? '');
            $cognome = trim($r['cognome'] ?? '');
            if ('' === $nome || '' === $cognome) {
                $errori[] = 'nome e cognome obbligatori';
            }
            $data = null;
            if ('' !== trim($r['data_nascita'] ?? '')) {
                $data = self::parseData(trim($r['data_nascita']));
                if (null === $data) {
                    $errori[] = 'data di nascita non valida';
                }
            }
            $gruppo = $gruppiPerNome[mb_strtolower(trim($r['gruppo'] ?? ''))] ?? null;
            if (null === $gruppo) {
                $errori[] = '' === trim($r['gruppo'] ?? '') ? 'gruppo mancante' : sprintf('gruppo "%s" non trovato', trim($r['gruppo']));
            }
            $referenti = [];
            for ($k = 1; $k <= 4; ++$k) {
                $rn = trim($r["ref{$k}_nome"] ?? '');
                $rc = trim($r["ref{$k}_cognome"] ?? '');
                $re = mb_strtolower(trim($r["ref{$k}_email"] ?? ''));
                if ('' === $rn && '' === $rc && '' === $re) {
                    continue;
                }
                if ('' !== $re && false === filter_var($re, \FILTER_VALIDATE_EMAIL)) {
                    $errori[] = sprintf('email del referente %d non valida', $k);
                }
                $referenti[] = ['nome' => $rn ?: '-', 'cognome' => $rc ?: '-', 'email' => '' === $re ? null : $re, 'relazione' => trim($r["ref{$k}_relazione"] ?? '') ?: null];
            }
            $chiave = mb_strtolower($nome.'|'.$cognome.'|'.$data?->format('Y-m-d').'|'.trim($r['gruppo'] ?? ''));
            if (isset($visti[$chiave])) {
                $errori[] = sprintf('ripetuto (riga %d)', $visti[$chiave]);
            }
            $visti[$chiave] = $n;

            $esistente = [] === $errori ? $this->trova($ente, $nome, $cognome, $data) : null;
            $risultato['righe'][] = [
                'riga' => $n,
                'nome' => $nome,
                'cognome' => $cognome,
                'gruppo' => $gruppo?->getNome() ?? trim($r['gruppo'] ?? ''),
                'referenti' => \count($referenti),
                'senzaEmail' => [] === array_filter($referenti, static fn ($x) => null !== $x['email']),
                'esistente' => null !== $esistente,
                'errori' => $errori,
            ];
            if ([] !== $errori) {
                ++$risultato['errori'];
                continue;
            }
            ++$risultato['valide'];
            null !== $esistente ? ++$risultato['esistenti'] : ++$risultato['nuovi'];
            if (!$applica) {
                continue;
            }
            $iscritto = $esistente ?? new Iscritto($ente, mb_substr($nome, 0, 100), mb_substr($cognome, 0, 100), $data);
            $iscritto->iscriviA($gruppo);
            foreach ($referenti as $ref) {
                if (null !== $ref['email'] && $this->haReferente($iscritto, $ref['email'])) {
                    continue;
                }
                new Referente($iscritto, mb_substr($ref['nome'], 0, 100), mb_substr($ref['cognome'], 0, 100), $ref['email'], null !== $ref['relazione'] ? mb_substr($ref['relazione'], 0, 50) : null);
            }
            $this->em->persist($iscritto);
        }
        if ($applica) {
            $this->em->flush();
        }

        return $risultato;
    }

    /** @return array<int, array<string, string>> righe indicizzate per numero di riga del file */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (\count($lines) < 2) {
            throw new DatiNonValidi('Il file deve avere una riga di intestazione e almeno un iscritto.');
        }
        if (\count($lines) - 1 > self::MAX_RIGHE) {
            throw new DatiNonValidi(sprintf('Al massimo %d iscritti per file.', self::MAX_RIGHE));
        }
        $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), str_getcsv($lines[0], $sep, '"', ''));
        if (!\in_array('nome', $header, true) || !\in_array('cognome', $header, true) || !\in_array('gruppo', $header, true)) {
            throw new DatiNonValidi('Intestazione: servono almeno le colonne nome, cognome e gruppo.');
        }
        $rows = [];
        foreach (\array_slice($lines, 1) as $i => $line) {
            if ('' === trim($line)) {
                continue;
            }
            $values = str_getcsv($line, $sep, '"', '');
            $row = [];
            foreach ($header as $j => $key) {
                $row[$key] = (string) ($values[$j] ?? '');
            }
            $rows[$i + 2] = $row;
        }

        return $rows;
    }

    public static function parseData(string $value): ?\DateTimeImmutable
    {
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y'] as $format) {
            $d = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $d && $d->format(substr($format, 1)) === $value) {
                return $d;
            }
        }

        return null;
    }

    private function trova(Tenant $ente, string $nome, string $cognome, ?\DateTimeImmutable $data): ?Iscritto
    {
        $qb = $this->em->createQueryBuilder()->select('i')->from(Iscritto::class, 'i')
            ->where('i.tenant = :t AND LOWER(i.nome) = :n AND LOWER(i.cognome) = :c')
            ->setParameter('t', $ente->getId(), 'uuid')
            ->setParameter('n', mb_strtolower($nome))
            ->setParameter('c', mb_strtolower($cognome))
            ->setMaxResults(1);
        null === $data ? $qb->andWhere('i.dataNascita IS NULL') : $qb->andWhere('i.dataNascita = :d')->setParameter('d', $data, 'date_immutable');

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function haReferente(Iscritto $iscritto, string $email): bool
    {
        foreach ($iscritto->getReferenti() as $r) {
            if ($r->getEmail() === $email) {
                return true;
            }
        }

        return false;
    }
}
