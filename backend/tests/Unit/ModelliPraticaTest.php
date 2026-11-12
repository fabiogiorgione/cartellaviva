<?php

namespace App\Tests\Unit;

use App\Pratiche\Modello\ModelliPratica;
use App\Pratiche\Modello\Requisito;
use App\Pratiche\Modello\TipoVoce;
use PHPUnit\Framework\TestCase;

/** I file di regole in config/pratiche si leggono tutti e rispettano il formato. */
final class ModelliPraticaTest extends TestCase
{
    private function modelli(): ModelliPratica
    {
        return new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche');
    }

    public function testRealRuleFilesLoad(): void
    {
        $tutti = $this->modelli()->tutti();
        self::assertNotEmpty($tutti);
        foreach ($tutti as $m) {
            self::assertNotEmpty($m->fonti, $m->titolo);
            self::assertNotEmpty($m->requisiti, $m->titolo);
        }
        $m = $this->modelli()->trova('730', 2025);
        self::assertNotNull($m);
        self::assertFalse($m->provvisorio());
        $s = $m->requisito('spesa_sanitaria');
        self::assertSame(TipoVoce::NON_RICHIESTO, $s->tipo('farmaci')?->tracciabile);
        self::assertSame(TipoVoce::DIPENDE, $s->tipo('visite')?->tracciabile);
        self::assertTrue($s->perPersona);
        self::assertTrue($m->requisito('cu')->obbligatorio);
        self::assertFalse($m->requisito('cu')->somma);
        self::assertContains('salute', $s->categorie);
        self::assertTrue($this->modelli()->trova('730', 2026)?->provvisorio());
        self::assertNull($this->modelli()->trova('730', 1999));
        self::assertSame(Requisito::DATA_DOPO, $m->requisito('cu')->data);
        self::assertSame(2026, $m->requisito('cu')->annoAtteso($m));

        // ISEE: anno della DSU, dati di due anni prima; rinnovo al 31 dicembre.
        $i = $this->modelli()->trova('isee', 2026);
        self::assertNotNull($i);
        self::assertSame(2024, $i->annoRiferimento());
        self::assertEquals([new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2026-12-31')], $i->finestra());
        self::assertSame('iban', $i->requisito('rapporti')->scopri);
        self::assertNull($i->requisito('rapporti')->annoAtteso($i));
        self::assertSame(2025, $i->requisito('redditi')->annoAtteso($i));
        self::assertSame('importo', $i->requisito('rapporti')->campi['saldo_precompilata']->confronta);
        self::assertNotNull($i->rinnovo);
        self::assertTrue($this->modelli()->trova('isee', 2027)?->provvisorio());
        self::assertEquals([new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-06-30')], $m->finestra());
    }

    public function testProposedYear(): void
    {
        $m = $this->modelli();
        self::assertSame(2025, $m->annoProposto('730', new \DateTimeImmutable('2026-05-10')));
        self::assertSame(2026, $m->annoProposto('730', new \DateTimeImmutable('2026-11-12')));
        // Senza il file dell'anno preferito si ripiega su quello vicino.
        self::assertSame(2026, $m->annoProposto('730', new \DateTimeImmutable('2027-11-12')));
        self::assertSame(2026, $m->annoProposto('isee', new \DateTimeImmutable('2026-05-10')));
        self::assertSame(2027, $m->annoProposto('isee', new \DateTimeImmutable('2026-11-12')));
    }

    public function testMalformedFilesAreRejected(): void
    {
        $base = ['modello' => '730', 'anno' => 2030, 'fonti' => [['titolo' => 'x', 'url' => 'https://x.it']], 'requisiti' => ['a_b' => ['etichetta' => 'A', 'campi' => ['importo' => ['tipo' => 'importo', 'etichetta' => 'I']]]]];
        self::assertSame(2030, ModelliPratica::leggi($base, '730-2030.yaml')->anno);
        foreach ([
            [['anno' => 2030] + $base, '730-2031.yaml'],
            [['fonti' => []] + $base, '730-2030.yaml'],
            [['fonti' => [['titolo' => 'x', 'url' => 'http://x.it']]] + $base, '730-2030.yaml'],
            [['modello' => 'boh'] + $base, '730-2030.yaml'],
            [['requisiti' => ['a' => ['etichetta' => 'A', 'campi' => ['x' => ['tipo' => 'colore', 'etichetta' => 'X']]]]] + $base, '730-2030.yaml'],
            [['requisiti' => ['ab' => ['etichetta' => 'A', 'campi' => ['tipo' => ['tipo' => 'scelta', 'etichetta' => 'T']]]]] + $base, '730-2030.yaml'],
            [['verificato' => 'ieri'] + $base, '730-2030.yaml'],
        ] as $i => [$dati, $file]) {
            try {
                ModelliPratica::leggi($dati, $file);
                self::fail("caso $i accettato");
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
