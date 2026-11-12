<?php

namespace App\Tests\Unit;

use App\Pratiche\Allenamento\Anonimizzatore;
use App\Pratiche\Allenamento\Valutazione;
use App\Pratiche\Modello\ModelliPratica;
use App\Pratiche\TestoVoci;
use PHPUnit\Framework\TestCase;

/** Gli esempi per l'allenamento non contengono i dati veri, e restano coerenti. */
final class AnonimizzatoreTest extends TestCase
{
    private const TESTO = "BANCA DI ESEMPIO SPA\nIntestatario: Mario Rossi, codice fiscale RSSMRA80A01F205X\nIBAN IT60 X054 2811 1010 0000 0123 456\nSaldo al 31/12/2024 euro 3.210,55\nGiacenza media 2.874,10\nCliente n. 123456789012\nTel. 333 1234567, mario.rossi@example.com\nAuto targa AB123CD\nData 12.03.2025";

    public function testPersonalDataIsReplacedConsistently(): void
    {
        $a = new Anonimizzatore(['Mario Rossi'], 42);
        $t = $a->testo(self::TESTO);
        foreach (['Mario', 'Rossi', 'RSSMRA80A01F205X', 'IT60', '0123 456', '3.210,55', '2.874,10', '123456789012', '333 1234567', 'mario.rossi@example.com', 'AB123CD'] as $vero) {
            self::assertStringNotContainsStringIgnoringCase($vero, $t, $vero);
        }
        // Restano: date (anche con i punti), aziende, la struttura del testo.
        self::assertStringContainsString('12.03.2025', $t);
        self::assertStringContainsString('BANCA DI ESEMPIO SPA', $t);
        self::assertStringContainsString('Saldo al 31/12/2024 euro ', $t);
        // L'IBAN finto è valido come i veri.
        self::assertCount(1, TestoVoci::iban($t));

        // I valori della risposta giusta seguono le stesse sostituzioni del testo.
        $v = $a->valori(['iban' => 'IT60X0542811101000000123456', 'importo' => '3210.55', 'giacenza' => '2874.10', 'emittente' => 'Mario Rossi', 'tipo' => 'conto']);
        self::assertSame(TestoVoci::iban($t)[0], $v['iban']);
        self::assertTrue(TestoVoci::importoNelTesto((int) round((float) $v['importo'] * 100), $t), $v['importo']);
        self::assertTrue(TestoVoci::importoNelTesto((int) round((float) $v['giacenza'] * 100), $t), $v['giacenza']);
        self::assertStringNotContainsString('Rossi', $v['emittente']);
        self::assertSame('conto', $v['tipo']);
    }

    public function testEvaluationCountsHitsMissesAndFields(): void
    {
        $m = (new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche'))->trova('730', 2025);
        $v = new Valutazione();
        $v->aggiungi($m, [
            ['requisito' => 'spesa_sanitaria', 'valori' => ['importo' => '14.40', 'tipo' => 'farmaci']],
            ['requisito' => 'cu', 'valori' => ['emittente' => 'ACME']],
        ], [
            ['requisito' => 'spesa_sanitaria', 'valori' => ['importo' => '14,40', 'tipo' => 'visite']],
            ['requisito' => 'interessi_mutuo', 'valori' => ['importo' => '39000.00']],
        ], ['cu']);
        self::assertSame(['oro' => 1, 'proposte' => 1, 'giuste' => 1, 'inPiu' => 0, 'mancate' => 0], $v->requisiti['spesa_sanitaria']);
        self::assertSame(1, $v->requisiti['cu']['mancate']);
        self::assertSame(1, $v->requisiti['interessi_mutuo']['inPiu']);
        self::assertSame(['confronti' => 1, 'uguali' => 1], $v->campi['spesa_sanitaria.importo']);
        self::assertSame(['confronti' => 1, 'uguali' => 0], $v->campi['spesa_sanitaria.tipo']);
        self::assertSame(['cu' => 1], $v->nonCandidati);
        self::assertSame(['precisione' => 0.5, 'richiamo' => 0.5], $v->totali());
    }
}
