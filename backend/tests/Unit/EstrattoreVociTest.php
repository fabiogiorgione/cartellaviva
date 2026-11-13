<?php

namespace App\Tests\Unit;

use App\Pratiche\AiVociSuggeritore;
use App\Pratiche\EstrattoreVoci;
use App\Pratiche\Modello\ModelliPratica;
use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\Modello\Requisito;
use App\Pratiche\TestoVoci;
use App\Pratiche\ValoriVoce;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Le voci proposte si ricontrollano sempre sul testo, con o senza AI. */
final class EstrattoreVociTest extends TestCase
{
    private const SCONTRINO = "FARMACIA CENTRALE SNC\nVia Roma 1, Milano\nScontrino parlante\nCodice fiscale del destinatario RSSMRA80A01F205X\nFarmaco 12,40\nTicket 2,00\nTOTALE EURO 14,40\nPagamento elettronico\n12/03/2025 10:41";

    private function modello(): ModelloPratica
    {
        return (new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche'))->trova('730', 2025);
    }

    private function estrattore(?string $risposta): EstrattoreVoci
    {
        $http = new MockHttpClient(static fn () => null === $risposta ? new MockResponse('giù', ['http_code' => 503]) : new MockResponse(json_encode(['message' => ['content' => $risposta]])));

        return new EstrattoreVoci(new AiVociSuggeritore($http, 'http://ollama.test', null === $risposta ? '' : 'm', 6000), new NullLogger());
    }

    public function testRulesOnlyGiveOneCheckedVoce(): void
    {
        $r = $this->estrattore(null)->estrai($this->modello(), 'farmaci', 'scontrino.jpg', self::SCONTRINO, [], null);
        self::assertSame('regole', $r['metodo']);
        self::assertCount(1, $r['voci']);
        self::assertSame('spesa_sanitaria', $r['voci'][0]['requisito']);
        self::assertEquals(['importo' => '14.40', 'data' => '2025-03-12', 'tipo' => 'farmaci', 'tracciabile' => 'si'], $r['voci'][0]['valori']);
    }

    public function testAiValuesMustAppearInTheText(): void
    {
        $ai = json_encode(['voci' => [[
            'requisito' => 'spesa_sanitaria', 'tipo' => 'dispositivi', 'importo' => '99,00', 'data' => '01/01/2025',
            'emittente' => 'Farmacia Centrale', 'persona' => '', 'tracciabile' => 'no', 'descrizione' => 'Farmaci <script>',
        ], [
            'requisito' => 'spesa_veterinaria', 'tipo' => 'visite', 'importo' => '14,40', 'data' => '', 'emittente' => '', 'persona' => '', 'tracciabile' => 'si', 'descrizione' => '',
        ]]]);
        $r = $this->estrattore($ai)->estrai($this->modello(), 'farmaci', 'scontrino.jpg', self::SCONTRINO, [], null);
        self::assertSame('ai', $r['metodo']);
        // La seconda voce è di un requisito non candidato (nessuna parola chiave): scartata.
        self::assertCount(1, $r['voci']);
        $v = $r['voci'][0]['valori'];
        // Importo e data inventati: si torna a quelli del testo. Il tracciabile lo dicono le regole.
        self::assertSame(['14.40', '2025-03-12', 'dispositivi', 'Farmacia Centrale', 'si', 'Farmaci script'], [$v['importo'], $v['data'], $v['tipo'], $v['emittente'], $v['tracciabile'], $v['descrizione']]);
    }

    public function testExpenseFromAnotherYearIsNotProposed(): void
    {
        $testo = str_replace('12/03/2025', '12/03/2024', self::SCONTRINO);
        self::assertSame([], $this->estrattore(null)->estrai($this->modello(), 'farmaci', 's.jpg', $testo, [], null)['voci']);
        // Un documento qualunque, senza categoria né parole chiave, non diventa nulla.
        self::assertSame([], $this->estrattore(null)->estrai($this->modello(), 'casa', 'x.pdf', 'Verbale di assemblea condominiale del 12/03/2025, totale 40,00 €', [], null)['voci']);
    }

    public function testIseeBankStatementGivesAnAccountWithSaldoAndGiacenza(): void
    {
        $isee = (new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche'))->trova('isee', 2026);
        $testo = "BANCA DI ESEMPIO SPA\nComunicazione annuale ai fini ISEE\nConto corrente IBAN IT60 X054 2811 1010 0000 0123 456\nSaldo contabile al 31/12/2024 euro 3.210,55\nGiacenza media annua 2024 euro 2.874,10\nBonifico in uscita a IT02L1234512345123456789012 (non è un IBAN valido)";
        $r = $this->estrattore(null)->estrai($isee, null, 'isee.pdf', $testo, [], null);
        self::assertCount(1, $r['voci']);
        self::assertEquals(['tipo' => 'conto', 'iban' => 'IT60X0542811101000000123456', 'importo' => '3210.55', 'giacenza' => '2874.10'], $r['voci'][0]['valori']);
        self::assertSame('rapporti', $r['voci'][0]['requisito']);
        // L'AI non scrive mai i valori della precompilata.
        $ai = json_encode(['voci' => [['requisito' => 'rapporti', 'tipo' => 'conto', 'persona' => '', 'emittente' => 'BANCA DI ESEMPIO SPA', 'iban' => 'IT60X0542811101000000123456', 'importo' => '3.210,55', 'giacenza' => '9.999,99', 'saldo_precompilata' => '1,00']]]);
        $v = $this->estrattore($ai)->estrai($isee, null, 'isee.pdf', $testo, [], null)['voci'][0]['valori'];
        self::assertSame(['2874.10', 'BANCA DI ESEMPIO SPA'], [$v['giacenza'], $v['emittente']]);
        self::assertArrayNotHasKey('saldo_precompilata', $v);
        self::assertSame(['IT60X0542811101000000123456'], TestoVoci::iban($testo));
        self::assertSame(['AB123CD'], TestoVoci::targhe('Carta di circolazione, targa: AB 123 CD'));
    }

    public function testRepeatedVociAndEchoedLabelsAreDropped(): void
    {
        $isee = (new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche'))->trova('isee', 2026);
        $testo = "Visura catastale\nRendita catastale euro 247,90\nFoglio 12 particella 34";
        $ai = json_encode(['voci' => [
            ['requisito' => 'immobili', 'tipo' => 'abitazione_principale', 'persona' => '', 'descrizione' => 'Casa in cui si vive', 'importo' => '247,90', 'mutuo_residuo' => ''],
            ['requisito' => 'immobili', 'tipo' => 'altro_fabbricato', 'persona' => '', 'descrizione' => 'Altro fabbricato', 'importo' => '247,90', 'mutuo_residuo' => ''],
            ['requisito' => 'immobili', 'tipo' => 'terreno', 'persona' => '', 'descrizione' => 'Foglio 12 particella 34', 'importo' => '247,90', 'mutuo_residuo' => ''],
        ]]);
        $voci = $this->estrattore($ai)->estrai($isee, null, 'visura.pdf', $testo, [], null)['voci'];
        self::assertCount(1, $voci);
        self::assertEquals(['tipo' => 'abitazione_principale', 'importo' => '247.90'], $voci[0]['valori']);
    }

    public function testKeywordsSurviveLineBreaksApostrophesAndAccents(): void
    {
        $isee = (new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche'))->trova('isee', 2026);
        $codici = static fn (string $testo, string $nome = 'x.pdf', bool $vecchio = false): array => array_map(static fn (Requisito $r) => $r->codice, EstrattoreVoci::candidati($isee, null, $testo, $nome, $vecchio));

        self::assertSame("certificazione unica d'imposta identita'", Requisito::normale("CERTIFICAZIONE\n   UNICA  D’IMPOSTA  Identità'"));
        self::assertSame('ci mario rossi', Requisito::nomeNormale('CI_Mario-Rossi.PDF'));
        // Il testo dei PDF va a capo dove capita, e l'OCR scrive gli accenti come può.
        self::assertSame(['redditi'], $codici("CERTIFICAZIONE\nUNICA 2025\nDATI RELATIVI AL SOSTITUTO D’IMPOSTA"));
        self::assertSame(['documento_identita'], $codici("REPUBBLICA ITALIANA\nCARTA DI IDENTITA' / IDENTITY CARD"));
        self::assertSame(['immobili'], $codici('Servizi Catastali - Visura attuale sintetica per soggetto'));

        // Una lettera dell'assicurazione cita la non autosufficienza: non è una certificazione di disabilità.
        self::assertSame([], $codici('Attestazione ai fini fiscali. Polizza n. 12: premi per il rischio di non autosufficienza versati nel 2024'));
        // Il rendiconto di una polizza si chiama "estratto conto" nel nome del file: è una polizza, non un conto.
        self::assertSame(['polizze'], $codici('Documento Unico di Rendicontazione al 12/10/2025 del suo investimento assicurativo', 'Estratto_Conto_Ramo_I.pdf'));
    }

    public function testFileNameAndOldDocuments(): void
    {
        $isee = (new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche'))->trova('isee', 2026);
        $codici = static fn (string $testo, string $nome, bool $vecchio = false): array => array_map(static fn (Requisito $r) => $r->codice, EstrattoreVoci::candidati($isee, null, $testo, $nome, $vecchio));

        // Una foto che l'OCR non ha letto si riconosce dal nome del file.
        self::assertSame(['documento_identita'], $codici('', 'ci_mario_rossi.pdf'));
        self::assertSame(['nucleo'], $codici('', 'stato-di-famiglia.pdf'));
        self::assertSame([], $codici('', 'cinema.pdf'));
        self::assertSame([], $codici('', 'ricevuta.pdf'));
        $ci = $this->estrattore(null)->estrai($isee, null, 'ci_mario_rossi.pdf', '', [], null);
        self::assertSame(['regole', [['requisito' => 'documento_identita', 'persona' => null, 'valori' => ['tipo' => 'carta_identita']]]], [$ci['metodo'], $ci['voci']]);
        self::assertSame('tessera_sanitaria', $this->estrattore(null)->estrai($isee, null, 'cf_mario.jpg', '', [], null)['voci'][0]['valori']['tipo']);

        // Più vecchi della finestra valgono solo per i documenti che non scadono con l'anno.
        self::assertSame(['immobili'], $codici('Visura catastale, rendita catastale euro 247,90', 'v.pdf', true));
        self::assertSame(['veicoli'], $codici('Carta di circolazione, targa AB123CD', 'l.pdf', true));
        self::assertSame([], $codici('Estratto conto, giacenza media annua euro 2.874,10', 'ec.pdf', true));
        self::assertSame([], $codici('Certificazione Unica 2019', 'cu.pdf', true));
        // Un curriculum cita la patente; un foglio sulla servitù cita una targa: non sono documenti per l'ISEE.
        self::assertSame([], $codici("Curriculum vitae\nEsperienza lavorativa\nPatente di guida B", 'cv.pdf'));
        self::assertSame([], $codici('Registro violazioni servitù: auto targa AB123CD parcheggiata', 'registro.xlsx'));
    }

    public function testLabelledAmountsBeatModelValuesFarFromTheLabel(): void
    {
        $isee = (new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche'))->trova('isee', 2026);
        // Estratto conto a tabella: la giacenza è sotto l'intestazione, il saldo finale altrove.
        $testo = "BANCA DI ESEMPIO\nEstratto conto al 31/12/2024\nIBAN IT60 X054 2811 1010 0000 0123 456\n"
            ."  31.01.2024                     1.100,00   Saldo finale\n"
            ."  31.12.2024                     1.250,00   Saldo finale\n\n"
            ."  TIPO       NUMERO      ANNO      GIACENZA MEDIA      DIVISA\n"
            ."  RAPPORTO   RAPPORTO\n\n"
            ."  01         ****1234    2024              987,65      EUR\n";
        // L'AI scambia il saldo per la giacenza: vale l'importo sotto "GIACENZA MEDIA".
        $ai = json_encode(['voci' => [['requisito' => 'rapporti', 'tipo' => 'conto', 'persona' => '', 'emittente' => 'BANCA DI ESEMPIO', 'iban' => 'IT60X0542811101000000123456', 'importo' => '1.100,00', 'giacenza' => '1.250,00']]]);
        $v = $this->estrattore($ai)->estrai($isee, null, 'ec.pdf', $testo, [], null)['voci'][0]['valori'];
        // Il saldo è l'ultimo "saldo finale" (fine anno), non quello di gennaio.
        self::assertSame(['1250.00', '987.65'], [$v['importo'], $v['giacenza']]);
        self::assertSame([123456], TestoVoci::importiVicini('1.234,56 GIACENZA MEDIA ISEE ANNO 2024', ['giacenza media']));
        // Una visura con due immobili: ogni voce tiene la sua rendita, perché sta accanto all'etichetta.
        $visura = "Visura catastale\nFoglio 1 particella 2 Rendita euro 247,90\nFoglio 3 particella 4 Rendita euro 512,30";
        $ai = json_encode(['voci' => [
            ['requisito' => 'immobili', 'tipo' => 'abitazione_principale', 'persona' => '', 'descrizione' => 'Foglio 1 particella 2', 'importo' => '247,90', 'mutuo_residuo' => ''],
            ['requisito' => 'immobili', 'tipo' => 'altro_fabbricato', 'persona' => '', 'descrizione' => 'Foglio 3 particella 4', 'importo' => '512,30', 'mutuo_residuo' => ''],
        ]]);
        self::assertSame(['247.90', '512.30'], array_map(static fn ($x) => $x['valori']['importo'], $this->estrattore($ai)->estrai($isee, null, 'visura.pdf', $visura, [], null)['voci']));
    }

    public function testTypeNamedInTheTitleWins(): void
    {
        $isee = (new ModelliPratica(\dirname(__DIR__, 2).'/config/pratiche'))->trova('isee', 2026);
        $cu = "ORIGINALE PER IL PERCIPIENTE\nCERTIFICAZIONE\nUNICA 2025\nDATI RELATIVI AL SOSTITUTO D'IMPOSTA\nReddito complessivo 21.000,00\nNote: dati utili per il modello 730";
        self::assertSame('cu', AiVociSuggeritore::tipoValido($isee->requisito('redditi'), 'dichiarazione_730', $cu));
        // Senza una frase del tipo nel titolo resta quello dell'AI.
        self::assertSame('dichiarazione_730', AiVociSuggeritore::tipoValido($isee->requisito('redditi'), 'dichiarazione_730', 'Prospetto di calcolo'));
    }

    public function testTextHelpers(): void
    {
        $lungo = 'Intestazione FARMACIA'.str_repeat(' riempitivo', 500).' Ticket 2,00 TOTALE EURO 14,40'.str_repeat(' fine', 300);
        $p = TestoVoci::porzioni($lungo, ['totale euro'], 1500);
        self::assertLessThanOrEqual(1500, mb_strlen($p));
        self::assertStringStartsWith('Intestazione FARMACIA', $p);
        self::assertStringContainsString('TOTALE EURO 14,40', $p);
        self::assertSame('breve', TestoVoci::porzioni('breve', ['x'], 1500));
        self::assertSame(123456, TestoVoci::importoTotale("Imponibile 1.000,00\nTotale documento € 1.234,56"));
        self::assertTrue(TestoVoci::importoNelTesto(123456, 'pagati 1.234,56 euro'));
        self::assertTrue(TestoVoci::importoNelTesto(4590, 'tot 45.90'));
        self::assertFalse(TestoVoci::importoNelTesto(4590, 'tot 145,90'));
        self::assertSame(['2025-03-12', '2025-12-01'], TestoVoci::date('12/03/2025 e 1.12.25 e 31/02/2025'));
        self::assertSame('no', TestoVoci::tracciabile('Pagato in contanti'));
        self::assertNull(TestoVoci::tracciabile('Grazie e arrivederci'));
        self::assertSame(4590, ValoriVoce::centesimi('45,90'));
        self::assertSame(123456, ValoriVoce::centesimi('1.234,56'));
        self::assertNull(ValoriVoce::centesimi('-3'));
        self::assertNull(ValoriVoce::centesimi('abc'));
        self::assertTrue(Requisito::contiene('farmacia centrale', 'farmac'));
        self::assertFalse(Requisito::contiene('la data è corretta', 'retta'));
        self::assertTrue(Requisito::contiene('retta di settembre', 'retta'));
    }
}
