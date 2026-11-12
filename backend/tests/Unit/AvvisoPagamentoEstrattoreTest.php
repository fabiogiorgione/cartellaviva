<?php

namespace App\Tests\Unit;

use App\Pratiche\AiPagamentoSuggeritore;
use App\Pratiche\AvvisoPagamentoEstrattore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AvvisoPagamentoEstrattoreTest extends TestCase
{
    private const OGGI = '2026-11-12';

    private const AVVISO = <<<'TXT'
        COMUNE DI MILANO
        Avviso di pagamento TARI 2026 - seconda rata
        Ente creditore: Comune di Milano
        Codice fiscale ente creditore 01199250158
        Codice avviso 3012 0000 4567 8912 34
        Importo da pagare € 180,40
        Da pagare entro il 30/11/2026
        Paga con pagoPA su app IO, home banking o presso i punti abilitati.
        TXT;

    private function estrai(string $testo): ?array
    {
        return (new AvvisoPagamentoEstrattore())->estrai($testo, new \DateTimeImmutable(self::OGGI));
    }

    public function testReadsAPagoPaNotice(): void
    {
        $a = $this->estrai(self::AVVISO);
        self::assertNotNull($a);
        self::assertSame('301200004567891234', $a['codiceAvviso']);
        self::assertSame(18040, $a['importoCentesimi']);
        self::assertSame('2026-11-30', $a['scadenza']?->format('Y-m-d'));
        self::assertSame('01199250158', $a['codiceFiscaleEnte']);
        self::assertSame('Comune di Milano', $a['ente']);
    }

    public function testCodeWithoutSpacesAndThousandsSeparator(): void
    {
        $a = $this->estrai("Bollettino PagoPA\nNumero avviso: 001234567890123456\nTotale da pagare euro 1.234,56\nScadenza 15 dicembre 2026");
        self::assertNotNull($a);
        self::assertSame('001234567890123456', $a['codiceAvviso']);
        self::assertSame(123456, $a['importoCentesimi']);
        self::assertSame('2026-12-15', $a['scadenza']?->format('Y-m-d'));
        self::assertNull($a['codiceFiscaleEnte']);
    }

    public function testMissingFieldsAreNull(): void
    {
        $a = $this->estrai("Avviso di pagamento\nCodice avviso 2012 0000 4567 8912 34");
        self::assertNotNull($a);
        self::assertNull($a['importoCentesimi']);
        self::assertNull($a['scadenza']);
        self::assertNull($a['ente']);
    }

    public function testPastDueDateIsIgnoredButRecentOneIsKept(): void
    {
        $vecchia = $this->estrai("Avviso di pagamento pagoPA\nCodice avviso 3012 0000 4567 8912 34\nImporto 10,00\nentro il 01/03/2026");
        self::assertNull($vecchia['scadenza']);
        $recente = $this->estrai("Avviso di pagamento pagoPA\nCodice avviso 3012 0000 4567 8912 34\nImporto 10,00\nentro il 05/11/2026");
        self::assertSame('2026-11-05', $recente['scadenza']?->format('Y-m-d'));
    }

    public function testUtilityBillWithoutNoticeCodeIsNotProposed(): void
    {
        self::assertNull($this->estrai("ENEL ENERGIA - Bolletta luce\nTotale da pagare € 87,40\nScadenza 20/11/2026\nPagabile con bollettino postale"));
    }

    public function testPlainTextAndEmptyAreNotProposed(): void
    {
        self::assertNull($this->estrai('Verbale di assemblea condominiale del 12/03/2026, presenti 18 condomini.'));
        self::assertNull($this->estrai(''));
        // Un numero di 18 cifre senza etichetta né parole tipiche dell'avviso non basta.
        self::assertNull($this->estrai('Ordine 3012 0000 4567 8912 34 spedito il 12/11/2026, totale 25,00 €'));
    }

    public function testCodesOf17Or19DigitsOrWrongPrefixAreRejected(): void
    {
        self::assertNull($this->estrai("Avviso pagoPA\nCodice avviso 3012 0000 4567 8912 3"));
        self::assertNull($this->estrai("Avviso pagoPA\nCodice avviso 3012 0000 4567 8912 345"));
        self::assertNull($this->estrai("Avviso pagoPA\nCodice avviso 4012 0000 4567 8912 34"));
        // Il numero senza etichetta "codice avviso" non è un codice avviso.
        self::assertNull($this->estrai("Avviso pagoPA\nTelefono 3012 0000 4567 8912 34"));
    }

    public function testNoticeCodeIsNotConfusedWithFiscalCode(): void
    {
        $a = $this->estrai("Avviso di pagamento\nEnte creditore Regione Lombardia Cod. fiscale 80050050154\nCodice avviso 3012 0000 4567 8912 34 Importo 55,00");
        self::assertSame('80050050154', $a['codiceFiscaleEnte']);
        self::assertSame('Regione Lombardia', $a['ente']);
        self::assertSame(5500, $a['importoCentesimi']);
    }

    public function testAiOnlyKeepsTitleAndEnteFoundInText(): void
    {
        $http = new MockHttpClient(static fn () => new MockResponse(json_encode(['message' => ['content' => '{"titolo":"TARI 2026, seconda rata <script>","ente":"Comune di Milano"}']])));
        $ai = new AiPagamentoSuggeritore($http, 'http://ollama.test:11434', 'm', 6000);
        self::assertTrue($ai->isEnabled());
        self::assertSame(['titolo' => 'TARI 2026, seconda rata script', 'ente' => 'Comune di Milano'], $ai->suggerisci('avviso.pdf', self::AVVISO));

        $inventato = new MockHttpClient(static fn () => new MockResponse(json_encode(['message' => ['content' => '{"titolo":"","ente":"Ministero Inventato"}']])));
        self::assertSame(['titolo' => null, 'ente' => null], (new AiPagamentoSuggeritore($inventato, 'http://ollama.test:11434', 'm', 6000))->suggerisci('a.pdf', self::AVVISO));

        self::assertFalse((new AiPagamentoSuggeritore($http, 'http://ollama.test:11434', '', 6000))->isEnabled());
    }
}
