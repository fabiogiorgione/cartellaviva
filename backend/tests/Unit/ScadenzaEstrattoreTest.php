<?php

namespace App\Tests\Unit;

use App\Scadenze\AiScadenzaSuggeritore;
use App\Scadenze\ScadenzaEstrattore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ScadenzaEstrattoreTest extends TestCase
{
    private const CARTA = <<<'TXT'
        REPUBBLICA ITALIANA - CARTA DI IDENTITA
        COGNOME ROSSI NOME MARIO
        LUOGO E DATA DI NASCITA ROMA 12/03/1980
        EMISSIONE 15.04.2025
        SCADENZA / EXPIRY 12.03.2036
        TXT;

    public function testFindsExpiryNearKeywordsAndIgnoresBirthAndIssue(): void
    {
        $oggi = new \DateTimeImmutable('2026-10-02');
        $e = new ScadenzaEstrattore();
        self::assertSame('2036-03-12', $e->migliore(self::CARTA, $oggi)?->format('Y-m-d'));
        // Nascita ed emissione sono nel passato: non sono nemmeno candidate.
        self::assertSame(['2036-03-12'], array_map(static fn ($c) => $c['data']->format('Y-m-d'), $e->candidati(self::CARTA, $oggi)));
    }

    public function testFormatsAndScoring(): void
    {
        $oggi = new \DateTimeImmutable('2026-10-02');
        $e = new ScadenzaEstrattore();
        $polizza = "Polizza RCA n. 123\nData stampa 2027-01-05\nCopertura valida fino al 31 dicembre 2027\nRinnovo tacito";
        $c = $e->candidati($polizza, $oggi);
        self::assertSame('2027-12-31', $c[0]['data']->format('Y-m-d'));
        self::assertGreaterThan(0, $c[0]['punteggio']);
        self::assertSame('2027-12-31', $e->migliore($polizza, $oggi)?->format('Y-m-d'));

        // Una data futura senza parole di scadenza: candidata, ma senza AI non si propone.
        self::assertNull($e->migliore('Appuntamento il 10/11/2026 alle 9', $oggi));
        self::assertCount(1, $e->candidati('Appuntamento il 10/11/2026 alle 9', $oggi));
        // Date impossibili o troppo lontane: scartate.
        self::assertSame([], $e->candidati('scadenza 31/02/2027 e scadenza 01/01/2099', $oggi));
    }

    public function testAiCanOnlyPickAmongFoundDates(): void
    {
        $date = [new \DateTimeImmutable('2036-03-12'), new \DateTimeImmutable('2027-01-01')];
        $richieste = [];
        $ai = $this->ai('{"scadenza":"2036-03-12","tipo":"Carta d\'identità <script>"}', $richieste);
        $r = $ai->scegli('carta.pdf', self::CARTA, $date);
        self::assertSame('2036-03-12', $r['data']?->format('Y-m-d'));
        self::assertSame("Carta d'identità script", $r['tipo']);
        $body = json_decode($richieste[0]['body'], true);
        self::assertSame(['2036-03-12', '2027-01-01', 'nessuna'], $body['format']['properties']['scadenza']['enum']);

        // Una data inventata (non nell'elenco) viene scartata.
        self::assertNull($this->ai('{"scadenza":"2030-01-01","tipo":"x"}', $richieste)->scegli('x', 'y', $date)['data']);
        self::assertNull($this->ai('{"scadenza":"nessuna","tipo":""}', $richieste)->scegli('x', 'y', $date)['data']);
        self::assertFalse((new AiScadenzaSuggeritore(new MockHttpClient(), 'http://ollama:11434', '', 6000))->isEnabled());
    }

    /** @param list<array<string, mixed>> $richieste */
    private function ai(string $risposta, array &$richieste): AiScadenzaSuggeritore
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($risposta, &$richieste): MockResponse {
            $richieste[] = ['url' => $url, 'body' => $options['body']];

            return new MockResponse(json_encode(['message' => ['content' => $risposta]]));
        });

        return new AiScadenzaSuggeritore($http, 'http://ollama:11434', 'm', 6000);
    }
}
