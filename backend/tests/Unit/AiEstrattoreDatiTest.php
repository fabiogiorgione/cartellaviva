<?php

namespace App\Tests\Unit;

use App\Documenti\Indice\AiEstrattoreDati;
use App\Documenti\Indice\SuddivisoreTesto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiEstrattoreDatiTest extends TestCase
{
    private const TESTO = "ENEL ENERGIA S.p.A.\nFattura n. 004512378 del 15/03/2025 - scadenza 5 aprile 2025\nIntestatario: MARIO ROSSI, Via Roma 10\nPOD IT001E12345678 - Targa: AB 123 CD\nCodice fiscale RSSMRA80A01F205X\nTotale da pagare: 1.287,40 euro";

    public function testSendsConstrainedRequestAndKeepsOnlyValuesFoundInTheText(): void
    {
        $requests = [];
        $estrattore = $this->estrattore([
            'emittente' => 'Enel Energia SpA', 'data' => '2025-03-15', 'importo' => '1287,40', 'numero' => '004512378',
            'intestatario' => 'Mario Rossi', 'riferimenti' => ['POD IT001E12345678', 'AB123CD', 'RSSMRA80A01F205X', 'ENEL', 'Enel Energia 1.287'], 'tag' => ['Luce', 'luce', ' energia elettrica! '],
        ], $requests);

        $d = $estrattore->estrai('bolletta.pdf', self::TESTO);

        self::assertSame(['emittente' => 'Enel Energia SpA', 'data' => '2025-03-15', 'importo' => '1287.40', 'numero' => '004512378', 'intestatario' => 'Mario Rossi'], $d->dati());
        self::assertSame(['IT001E12345678', 'AB123CD'], $d->riferimenti, 'etichetta tolta, codice fiscale e parole senza cifre esclusi');
        self::assertSame(['luce', 'energia elettrica'], $d->tag);

        $body = json_decode($requests[0]['body'], true);
        self::assertSame('http://ollama:11434/api/chat', $requests[0]['url']);
        self::assertSame(['2025-03-15', '2025-04-05', ''], $body['format']['properties']['data']['enum'], 'la data si sceglie solo tra quelle del testo');
        self::assertSame(0, $body['options']['temperature']);
    }

    public function testInventedValuesAreDropped(): void
    {
        $requests = [];
        $d = $this->estrattore([
            'emittente' => 'Acea Ato 2', 'data' => '2030-01-01', 'importo' => '99,90', 'numero' => 'FT-999',
            'intestatario' => 'Giulia Verdi', 'riferimenti' => ['ZZ999ZZ'], 'tag' => [],
        ], $requests)->estrai('x.pdf', self::TESTO);

        self::assertSame(['emittente' => null, 'data' => null, 'importo' => null, 'numero' => null, 'intestatario' => null], $d->dati());
        self::assertSame([], $d->riferimenti);
    }

    public function testNonJsonAnswerGivesEmptyDataAndHttpErrorIsRaised(): void
    {
        $requests = [];
        $estrattore = new AiEstrattoreDati(new MockHttpClient(new MockResponse(json_encode(['message' => ['content' => 'Ecco i dati: ...']]))), 'http://ollama:11434', 'm', 6000);
        self::assertSame([], array_filter($estrattore->estrai('x.pdf', self::TESTO)->dati()));

        $estrattore = new AiEstrattoreDati(new MockHttpClient(new MockResponse('{"error":"model not found"}', ['http_code' => 404])), 'http://ollama:11434', 'm', 6000);
        $this->expectException(\RuntimeException::class);
        $estrattore->estrai('x.pdf', self::TESTO);
    }

    public function testDatesInTheText(): void
    {
        self::assertSame(
            ['2025-03-15', '2024-12-01', '2025-04-05', '2026-02-28'],
            AiEstrattoreDati::dateNelTesto('del 15/03/2025, poi 1.12.2024 e 2025-04-05; il 5 aprile 2025 e il 15-03-2025 di nuovo; 28 febbraio 2026; 31/02/2025 non esiste'),
        );
    }

    public function testIntegerAmountMatchesOnlyAsAWholeNumber(): void
    {
        $requests = [];
        $e = $this->estrattore(['importo' => '540'], $requests);
        self::assertSame('540.00', $e->estrai('x.pdf', 'Premio annuo 540 euro')->importo);
        self::assertNull($e->estrai('x.pdf', 'Codice 15400 euro')->importo);
    }

    public function testTextIsSplitIntoBoundedParts(): void
    {
        self::assertSame(['riga uno'."\n".'riga due'], SuddivisoreTesto::parti("riga   uno\n\n riga due \n"));
        self::assertSame([], SuddivisoreTesto::parti(" \n "));

        $parti = SuddivisoreTesto::parti(str_repeat('Una frase di prova. ', 400), 300, 5);
        self::assertCount(5, $parti, 'oltre il massimo il resto non si indicizza');
        foreach ($parti as $p) {
            self::assertLessThanOrEqual(300, mb_strlen($p));
        }
        // Una parola lunghissima senza spazi si spezza comunque.
        self::assertSame([300, 300, 100], array_map('mb_strlen', SuddivisoreTesto::parti(str_repeat('x', 700), 300)));
    }

    /**
     * @param array<string, mixed> $risposta
     * @param list<array{url: string, body: string}> $requests
     */
    private function estrattore(array $risposta, array &$requests): AiEstrattoreDati
    {
        $risposta += ['emittente' => '', 'data' => '', 'importo' => '', 'numero' => '', 'intestatario' => '', 'riferimenti' => [], 'tag' => []];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($risposta, &$requests) {
            $requests[] = ['url' => $url, 'body' => (string) $options['body']];

            return new MockResponse(json_encode(['message' => ['content' => json_encode($risposta)]]));
        });

        return new AiEstrattoreDati($http, 'http://ollama:11434', 'm', 6000);
    }
}
