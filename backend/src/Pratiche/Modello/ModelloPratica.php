<?php

namespace App\Pratiche\Modello;

/** Modello di pratica per un anno (es. 730 per le spese del 2025), letto da config/pratiche. */
final class ModelloPratica
{
    /**
     * @param list<array{titolo: string, url: string}> $fonti
     * @param array<string, Requisito>                 $requisiti
     */
    public function __construct(
        public readonly string $codice,
        public readonly int $anno,
        public readonly string $titolo,
        public readonly string $descrizione,
        public readonly ?string $verificato,
        public readonly array $fonti,
        public readonly ?string $note,
        public readonly array $requisiti,
        /** anni dall'anno della pratica a quello dei dati: 0 per il 730 (spese dell'anno), -2 per l'ISEE */
        public readonly int $riferimento = 0,
        /** @var array{titolo: string, giorni: int}|null promemoria del rinnovo al 31/12 dell'anno della pratica */
        public readonly ?array $rinnovo = null,
    ) {
    }

    public function annoRiferimento(): int
    {
        return $this->anno + $this->riferimento;
    }

    /** Documenti da guardare: dal 1° gennaio dell'anno di riferimento al 30 giugno dopo, o a fine anno della pratica se è più tardi. */
    public function finestra(): array
    {
        $rif = $this->annoRiferimento();
        $fine = max(new \DateTimeImmutable(($rif + 1).'-06-30'), new \DateTimeImmutable($this->anno.'-12-31'));

        return [new \DateTimeImmutable("$rif-01-01"), $fine];
    }

    /** C'è almeno un requisito per cui valgono anche i documenti più vecchi della finestra? */
    public function guardaAncheVecchi(): bool
    {
        foreach ($this->requisiti as $r) {
            if ($r->ancheVecchi) {
                return true;
            }
        }

        return false;
    }

    public function requisito(string $codice): ?Requisito
    {
        return $this->requisiti[$codice] ?? null;
    }

    /** Regole non ancora ricontrollate sulle istruzioni ufficiali dell'anno. */
    public function provvisorio(): bool
    {
        return null === $this->verificato;
    }

    /** @return array<string, mixed> */
    public function vista(bool $conRequisiti = true): array
    {
        return [
            'modello' => $this->codice,
            'anno' => $this->anno,
            'annoRiferimento' => $this->annoRiferimento(),
            'titolo' => $this->titolo,
            'descrizione' => $this->descrizione,
            'verificato' => $this->verificato,
            'provvisorio' => $this->provvisorio(),
            'fonti' => $this->fonti,
            'note' => $this->note,
            ...($conRequisiti ? ['requisiti' => array_values(array_map(static fn (Requisito $r) => $r->vista(), $this->requisiti))] : []),
        ];
    }
}
