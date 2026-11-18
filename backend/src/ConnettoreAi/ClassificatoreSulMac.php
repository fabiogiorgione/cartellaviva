<?php

namespace App\ConnettoreAi;

use App\Documenti\Classificazione\AiCategoryClassifier;
use App\Documenti\Classificazione\ClassificationResult;
use App\Documenti\Classificazione\ClassifierInterface;
use App\Documenti\Classificazione\NuovaCategoria;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Il classificatore quando le domande le fa il Mac della famiglia. Le domande e
 * l'interpretazione delle risposte sono quelle di AiCategoryClassifier; qui si
 * decide solo chi risponde:
 *
 * - una domanda già fatta: il risultato salvato (già interpretato);
 * - la domanda del passo in corso, con la risposta del Mac: la si interpreta;
 * - qualsiasi altra: InAttesaDelMac, e quella diventa il passo successivo.
 *
 * Una domanda si riconosce dall'impronta di istruzioni, prompt e schema: se nel
 * frattempo cambiano il documento o le categorie, cambia la domanda e la si rifà.
 */
#[Exclude] // non è un servizio: se ne crea uno per ogni passo di un lavoro
final class ClassificatoreSulMac implements ClassifierInterface
{
    private bool $rispostaUsata = false;

    /**
     * @param array<string, mixed> $risposte impronta della domanda => risultato interpretato
     */
    public function __construct(
        private readonly AiCategoryClassifier $ai,
        private array $risposte,
        private readonly ?string $passo,
        private ?string $grezza,
        private readonly string $modello,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function classify(string $nomeFile, string $testo, array $categorie, array $esempi = [], array $indizi = []): ClassificationResult
    {
        $v = $this->risposta($this->ai->richiestaClassifica($nomeFile, $testo, $categorie, $esempi, $indizi), function (string $raw) use ($categorie): array {
            $r = $this->ai->interpretaClassifica($raw, $categorie, $this->modello);
            // Per l'audit basta la scelta del modello (una voce dell'elenco o "nessuna"), non la risposta intera.
            $d = json_decode($raw, true);
            $scelta = \is_array($d) && \is_string($d['categoria'] ?? null) ? mb_substr($d['categoria'], 0, 100) : '';

            return ['categoria' => $r->categoria, 'risposta' => json_encode(['categoria' => $scelta], \JSON_UNESCAPED_UNICODE)];
        });

        return new ClassificationResult($v['categoria'], (string) $v['risposta'], $this->modello);
    }

    public function descriviCategoria(string $categoria, array $esempi): string
    {
        return (string) $this->risposta($this->ai->richiestaDescrizione($categoria, $esempi), fn (string $raw) => $this->ai->interpretaDescrizione($raw, $categoria));
    }

    public function verifica(string $nomeFile, string $testo, string $categoria, string $descrizione, array $indizi): bool
    {
        return true === $this->risposta($this->ai->richiestaVerifica($nomeFile, $testo, $categoria, $descrizione, $indizi), fn (string $raw) => $this->ai->interpretaVerifica($raw));
    }

    public function proponiNuova(string $nomeFile, string $testo, array $indizi, array $esistenti): ?NuovaCategoria
    {
        $v = $this->risposta($this->ai->richiestaNuova($nomeFile, $testo, $indizi, $esistenti), function (string $raw) use ($esistenti): ?array {
            $n = $this->ai->interpretaNuova($raw, $esistenti, $this->modello);

            return null === $n ? null : ['nome' => $n->nome, 'sensibile' => $n->sensibile, 'icona' => $n->icona];
        });

        return \is_array($v) ? new NuovaCategoria((string) $v['nome'], true === $v['sensibile'], (string) $v['icona'], $this->modello) : null;
    }

    /** @return array<string, mixed> i risultati delle domande fatte finora */
    public function risposte(): array
    {
        return $this->risposte;
    }

    /** La risposta del Mac è servita (la domanda del passo c'era ancora)? */
    public function rispostaUsata(): bool
    {
        return $this->rispostaUsata;
    }

    /** @param array{istruzioni: string, prompt: string, schema: string} $richiesta */
    public static function impronta(array $richiesta): string
    {
        return hash('sha256', json_encode($richiesta, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array{istruzioni: string, prompt: string, schema: string} $richiesta
     * @param callable(string): mixed                                    $interpreta
     */
    private function risposta(array $richiesta, callable $interpreta): mixed
    {
        $chiave = self::impronta($richiesta);
        if (\array_key_exists($chiave, $this->risposte)) {
            return $this->risposte[$chiave];
        }
        if ($chiave === $this->passo && null !== $this->grezza) {
            $v = $interpreta($this->grezza);
            $this->grezza = null;
            $this->rispostaUsata = true;

            return $this->risposte[$chiave] = $v;
        }

        throw new InAttesaDelMac($richiesta, $chiave);
    }
}
