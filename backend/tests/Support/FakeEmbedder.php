<?php

namespace App\Tests\Support;

use App\Documenti\Indice\EmbedderInterface;

/**
 * Embedding finto e deterministico: ogni parola (le prime 5 lettere, così
 * "bolletta" e "bollette" coincidono) accende una delle 256 dimensioni.
 * Testi con parole in comune hanno vettori vicini, testi senza parole in
 * comune hanno distanza 1.
 */
final class FakeEmbedder implements EmbedderInterface
{
    public bool $enabled = true;
    public bool $guasto = false;
    public bool $modelloMancante = false;

    /** @var list<list<string>> */
    public array $calls = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function modello(): string
    {
        return 'embed-di-test';
    }

    public function embed(array $testi, float $timeout = 300): array
    {
        $this->calls[] = $testi;
        if ($this->guasto) {
            throw new \RuntimeException('Ollama non risponde');
        }
        if ($this->modelloMancante) {
            throw new \App\Documenti\Indice\ModelloNonDisponibileException('Modello di embedding non scaricato');
        }

        return array_map(self::vettore(...), $testi);
    }

    /** @return list<float> */
    public static function vettore(string $testo): array
    {
        $v = array_fill(0, 256, 0.0);
        preg_match_all('/\p{L}{3,}/u', mb_strtolower($testo), $m);
        foreach ($m[0] as $parola) {
            // "Documento:" è il prefisso di ogni parte: non deve avvicinare tutto a tutto.
            if ('documento' !== $parola) {
                $v[crc32(mb_substr($parola, 0, 5)) % 256] += 1.0;
            }
        }
        $norma = sqrt(array_sum(array_map(static fn (float $x) => $x * $x, $v)));
        if (0.0 === $norma) {
            $v[0] = 1.0;

            return $v;
        }

        return array_map(static fn (float $x) => $x / $norma, $v);
    }
}
