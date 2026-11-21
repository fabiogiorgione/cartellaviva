<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\Classificazione\NuovaCategoria;
use App\Documenti\Classificazione\PropostaCategoria;
use App\Documenti\Message\OcrDocumentoMessage;
use App\Documenti\MessageHandler\OcrDocumentoHandler;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tests\Support\FakeClassifier;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Wizard "Proponi categoria con l'AI": una proposta per documento, tra le
 * categorie esistenti o una nuova; il documento non cambia.
 */
final class PropostaCategoriaTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
        $this->token = $this->login($this->owner);
    }

    public function testProposesAnExistingCategoryWithExamplesAndChangesNothing(): void
    {
        $this->carica('Bolletta luce marzo', 'bollette', 'Enel energia, fattura luce');
        $this->carica('DOC_1660607446', 'bollette', 'Enel energia');
        $insieme = $this->carica('Bolletta gas aprile', 'bollette', 'Hera gas');
        $doc = $this->carica('scansione 12', 'casa', 'Enel energia: bolletta della luce, totale 87,40 euro');
        $this->classifier()->risposta = 'Bollette';

        $esito = $this->proponi($doc['id'], escludi: [$insieme['id'], 'non-un-id']);

        self::assertSame(['tipo' => 'esistente', 'categoriaId' => (string) $this->categoria('bollette')->getId(), 'nome' => 'Bollette', 'fonte' => 'testo'], $esito);
        $call = $this->classifier()->calls[0];
        self::assertSame('Enel energia: bolletta della luce, totale 87,40 euro', $call['testo']);
        self::assertContains('Titolo: scansione 12', $call['indizi']);
        self::assertSame(['Bolletta luce marzo'], $call['esempi']['Bollette'], 'esempi senza il documento stesso, quelli in revisione insieme e i nomi di file');
        self::assertArrayNotHasKey('Casa', $call['esempi']);
        self::assertNotContains('Da categorizzare', $call['categorie'], 'le categorie di servizio non si propongono');
        self::assertNotContains('Condivisi con enti', $call['categorie']);
        self::assertCount(1, $this->classifier()->callsVerifica, 'la scelta si verifica contro la descrizione della categoria');
        self::assertSame('Bollette', $this->classifier()->callsVerifica[0]['categoria']);
        self::assertStringStartsWith('bollette, fatture e solleciti di luce', $this->classifier()->callsVerifica[0]['descrizione'], 'di sistema: la descrizione scritta a mano');
        self::assertSame([], $this->classifier()->callsDescrizione, 'nessuna domanda al modello per descriverla');
        self::assertSame([], $this->classifier()->callsNuova, 'trovata una categoria: niente categoria nuova');

        $dettaglio = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}", token: $this->token);
        self::assertSame((string) $this->categoria('casa')->getId(), $dettaglio['categoria']['id'], 'la proposta non sposta il documento');
    }

    public function testProposesANewCategoryAndReusesItForSimilarDocuments(): void
    {
        $primo = $this->carica('Visita Fido', 'casa', 'Clinica veterinaria San Rocco: vaccinazione del cane Fido');
        $this->classifier()->nuova = new NuovaCategoria('Veterinario', false, 'paw-print', 'fake');

        self::assertSame(['tipo' => 'nuova', 'nome' => 'Veterinario', 'sensibile' => false, 'icona' => 'paw-print', 'fonte' => 'testo'], $this->proponi($primo['id']));
        self::assertContains('Bollette', $this->classifier()->callsNuova[0]['esistenti']);

        // Il documento dopo ha la categoria nuova tra le scelte: documenti simili finiscono insieme.
        $secondo = $this->carica('Fattura toelettatura', 'casa', 'Toelettatura del cane');
        $this->classifier()->risposta = 'Veterinario';
        $esito = $this->proponi($secondo['id'], ['Veterinario', 'bollette', '<script>']);
        self::assertSame(['tipo' => 'nuova', 'nome' => 'Veterinario', 'sensibile' => null, 'icona' => null, 'fonte' => 'testo'], $esito);
        $categorie = $this->classifier()->calls[1]['categorie'];
        self::assertContains('Veterinario', $categorie);
        self::assertSame(1, \count(array_filter($categorie, static fn (string $c) => 'bollette' === mb_strtolower($c))), 'un nome già esistente non si duplica');
        self::assertNotContains('<script>', $categorie, 'nomi non validi scartati');
    }

    public function testAForcedChoiceRejectedByTheCheckBecomesANewCategory(): void
    {
        $doc = $this->carica('Volo Lisbona', 'casa', 'Agenzia viaggi: conferma prenotazione volo Venezia - Lisbona');
        $this->classifier()->risposta = 'Veicoli';
        $this->classifier()->verificata = false;
        $this->classifier()->nuova = new NuovaCategoria('Viaggi', false, 'plane', 'fake');

        self::assertSame(['tipo' => 'nuova', 'nome' => 'Viaggi', 'sensibile' => false, 'icona' => 'plane', 'fonte' => 'testo'], $this->proponi($doc['id']));
        self::assertSame('Veicoli', $this->classifier()->callsVerifica[0]['categoria']);
    }

    public function testAFamilyCategoryIsDescribedByTheModel(): void
    {
        $sport = (array) $this->request('POST', "/api/tenants/{$this->tid()}/categorie", ['nome' => 'Sport', 'icona' => 'trophy', 'colore' => '#10b981'], $this->token);
        $this->assertStatus(201);
        $this->upload($this->tid(), $this->token, 'Iscrizione corso di nuoto', 'nuoto.txt', ['titolo' => 'Corso nuoto Sara', 'categoriaId' => $sport['id']]);
        $doc = $this->carica('scansione 3', 'casa', 'Palestra Esempio: abbonamento annuale');
        $this->classifier()->risposta = 'Sport';

        self::assertSame(['tipo' => 'esistente', 'categoriaId' => $sport['id'], 'nome' => 'Sport', 'fonte' => 'testo'], $this->proponi($doc['id']));
        self::assertSame([['categoria' => 'Sport', 'esempi' => ['Corso nuoto Sara']]], $this->classifier()->callsDescrizione);
        self::assertSame([['categoria' => 'Sport', 'descrizione' => 'documenti di tipo Sport']], $this->classifier()->callsVerifica);
    }

    public function testNoProposalWhenTheModelCannotTell(): void
    {
        $doc = $this->carica('documento', 'casa', 'pagina 1 di 2');

        self::assertSame(['tipo' => 'nessuna', 'motivo' => 'Nessuna categoria sembra adatta.', 'fonte' => 'testo'], $this->proponi($doc['id']));
        self::assertCount(1, $this->classifier()->callsNuova);
    }

    public function testEncryptedDocumentsAreReadInMemoryForTheLocalModel(): void
    {
        $doc = $this->carica('Referto', 'salute', 'Referto: esame del sangue, emocromo');
        self::assertTrue($doc['cifrato']);
        $this->classifier()->risposta = 'Salute';

        self::assertSame('esistente', $this->proponi($doc['id'])['tipo']);
        self::assertSame('Referto: esame del sangue, emocromo', $this->classifier()->calls[0]['testo']);
    }

    public function testWithoutTextItDecidesFromTheNameAndProposesNothingNew(): void
    {
        $doc = $this->carica('Polizza auto 2026', 'casa', null);
        $this->classifier()->nuova = new NuovaCategoria('Auto', false, 'car', 'fake');

        $esito = $this->proponi($doc['id']);
        self::assertSame('nessuna', $esito['tipo']);
        self::assertSame('nome', $esito['fonte']);
        self::assertSame('', $this->classifier()->calls[0]['testo']);
        self::assertSame([], $this->classifier()->callsNuova, 'dal solo nome non si inventano categorie');
    }

    public function testAccessAndConfiguration(): void
    {
        $doc = $this->carica('Bolletta', 'casa', 'luce');

        $viewer = $this->createUser('viewer@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}/proposta-categoria", [], $this->login($viewer));
        $this->assertStatus(403);

        $altra = $this->createTenant('Famiglia Bianchi');
        $this->addMember($this->owner, $altra, Role::OWNER);
        $this->request('POST', "/api/tenants/{$altra->getId()}/documenti/{$doc['id']}/proposta-categoria", [], $this->token);
        $this->assertStatus(404);

        $this->classifier()->enabled = false;
        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}/proposta-categoria", [], $this->token);
        $this->assertStatus(409);
    }

    public function testOneProposalAtATimePerUserAndFewOnThePlatform(): void
    {
        $doc = $this->carica('Bolletta', 'casa', 'luce');
        $altra = $this->altraConnessione();

        // L'utente ha già una proposta in corso (un'altra richiesta tiene il suo turno).
        $altra->fetchOne("SELECT pg_advisory_lock(hashtext('proposta_categoria_utente'), hashtext(:u))", ['u' => (string) $this->owner->getId()]);
        $this->assertOccupato($doc['id'], 'già una proposta in corso');
        $altra->fetchOne("SELECT pg_advisory_unlock_all()");

        // Tutti i posti della piattaforma occupati da altri.
        foreach ([0, 1] as $posto) {
            $altra->fetchOne("SELECT pg_advisory_lock(hashtext('proposta_categoria_posto'), hashtext(:p))", ['p' => (string) $posto]);
        }
        $this->assertOccupato($doc['id'], 'altri');
        self::assertSame([], $this->classifier()->calls, 'senza turno il modello non viene chiamato');
        $altra->fetchOne("SELECT pg_advisory_unlock_all()");
        $altra->close();

        // Liberi i turni si passa, e dopo la risposta i turni tornano liberi.
        $this->proponi($doc['id']);
        $this->proponi($doc['id']);
    }

    public function testHourlyLimitPerUser(): void
    {
        $doc = $this->carica('Bolletta', 'casa', 'luce');
        for ($i = 0; $i < 4; ++$i) {
            $this->proponi($doc['id']);
        }
        $this->request('POST', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}/proposta-categoria", [], $this->token);
        $this->assertStatus(429);
        self::assertGreaterThan(60, (int) $this->client->getResponse()->headers->get('Retry-After'), 'oltre il limite orario si aspetta a lungo: il wizard si ferma');
    }

    public function testFileNameTitlesSayNothing(): void
    {
        foreach (['DOC_1660607446', 'CAD30697-signed', 'Scansione 12', 'IMG_2041', 'Documento senza titolo'] as $t) {
            self::assertFalse(PropostaCategoria::titoloParlante($t), $t);
        }
        foreach (['Bolletta luce marzo', 'polizza_auto', 'estinzione mutuo', 'Pagella 2025'] as $t) {
            self::assertTrue(PropostaCategoria::titoloParlante($t), $t);
        }
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array<string, mixed> il documento caricato: un TXT letto dall'OCR, o con $testo null una foto non ancora letta */
    public function testWithTheMacOnlineTheProposalIsMadeOnTheMacStepByStep(): void
    {
        $access = $this->macOnline($this->tid(), $this->token);
        $utenze = (array) $this->request('POST', "/api/tenants/{$this->tid()}/categorie", ['nome' => 'Utenze', 'icona' => 'zap', 'colore' => '#f59e0b'], $this->token);
        $this->assertStatus(201);
        $this->upload($this->tid(), $this->token, 'Luce Demo, fattura luce', 'luce.txt', ['titolo' => 'Bolletta luce marzo', 'categoriaId' => $utenze['id']]);
        $doc = $this->carica('scansione 12', 'casa', 'Luce Demo: bolletta della luce, totale 87,40 euro');

        // La prima richiesta avvia la proposta sul Mac: il wizard aspetta e ripete, come per un turno occupato.
        $this->assertSulMac($doc['id']);
        $this->assertSulMac($doc['id']);
        self::assertSame(1, $this->lavori());

        // 1. La scelta tra le categorie.
        $l = $this->lavoroDelMac($access);
        self::assertSame(['proposta_categoria', 'Proposta di categoria', 'scansione 12'], [$l['tipo'], $l['pratica'], $l['documento']]);
        self::assertStringContainsString('totale 87,40 euro', $l['prompt']);
        self::assertStringContainsString('- Utenze (vi si trovano già, ad esempio: "Bolletta luce marzo")', $l['prompt']);
        self::assertContains('Utenze', json_decode($l['schema'], true)['properties']['categoria']['enum']);
        $this->rispondi($access, $l, ['categoria' => 'Utenze']);
        $this->assertSulMac($doc['id']);

        // 2. La descrizione della categoria (della famiglia: la scrive il modello), poi 3. la verifica sì/no.
        $l = $this->lavoroDelMac($access);
        self::assertStringContainsString('Categoria: Utenze', $l['prompt']);
        $this->rispondi($access, $l, ['descrizione' => 'bollette di luce, gas e acqua']);
        $l = $this->lavoroDelMac($access);
        self::assertStringContainsString('Raccoglie: bollette di luce, gas e acqua', $l['prompt']);
        $this->rispondi($access, $l, ['argomento' => 'bolletta della luce', 'giusta' => true]);
        self::assertNull($this->lavoroDelMac($access), 'catena finita: niente altro per il Mac');

        // La richiesta dopo ha l'esito, una volta sola; il server non ha chiamato il suo modello.
        self::assertSame(['tipo' => 'esistente', 'categoriaId' => $utenze['id'], 'nome' => 'Utenze', 'fonte' => 'testo'], $this->proponi($doc['id']));
        self::assertSame([[], [], [], []], [$this->classifier()->calls, $this->classifier()->callsDescrizione, $this->classifier()->callsVerifica, $this->classifier()->callsNuova]);
        self::assertSame(0, $this->lavori());
        $audit = $this->auditRows('documento.categoria_proposta');
        $contesto = json_decode((string) end($audit)['context'], true);
        self::assertSame(['esito' => 'esistente', 'modello' => 'qwen3:4b-instruct-2507-q4_K_M', 'proposta' => 'Utenze'], array_intersect_key($contesto, ['esito' => 1, 'modello' => 1, 'proposta' => 1]));
    }

    public function testARejectedChoiceOnTheMacEndsWithANewCategoryAndEncryptedDocumentsGoToo(): void
    {
        $access = $this->macOnline($this->tid(), $this->token);
        $doc = $this->carica('Referto', 'salute', 'Clinica veterinaria San Rocco: vaccinazione del cane Fido');
        self::assertTrue($this->em()->getConnection()->fetchOne('SELECT cifrato FROM documento WHERE id = :id', ['id' => $doc['id']]));
        $this->assertSulMac($doc['id']);
        $l = $this->lavoroDelMac($access);
        self::assertStringContainsString('vaccinazione del cane Fido', $l['prompt'], 'anche un cifrato va al Mac, decifrato in memoria');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM lavoro_ai WHERE dati::text LIKE '%Fido%'"));
        $this->rispondi($access, $l, ['categoria' => 'Casa']);
        // Casa è di sistema: la verifica subito, con la descrizione scritta a mano.
        $l = $this->lavoroDelMac($access);
        self::assertStringContainsString('Raccoglie: rogito, mutuo', $l['prompt']);
        $this->rispondi($access, $l, ['argomento' => 'vaccino del cane', 'giusta' => false]);
        // Dopo due passi, nel lavoro solo risultati interpretati: né testo né risposte del modello.
        $dati = (string) $this->em()->getConnection()->fetchOne('SELECT dati::text FROM lavoro_ai');
        self::assertStringNotContainsString('Fido', $dati);
        self::assertStringNotContainsString('vaccino', $dati);
        $l = $this->lavoroDelMac($access);
        self::assertStringContainsString('proponi UNA nuova categoria', $l['istruzioni']);
        $this->rispondi($access, $l, ['nome' => 'veterinario', 'sensibile' => false, 'simbolo' => 'paw-print']);

        self::assertSame(['tipo' => 'nuova', 'nome' => 'Veterinario', 'sensibile' => false, 'icona' => 'paw-print', 'fonte' => 'testo'], $this->proponi($doc['id']));
        // Di un cifrato l'audit non dice la proposta, come sul server.
        $audit = $this->auditRows('documento.categoria_proposta');
        self::assertArrayNotHasKey('proposta', json_decode((string) end($audit)['context'], true));
    }

    public function testAnAnswerToAQuestionThatChangedMeanwhileIsAskedAgain(): void
    {
        $access = $this->macOnline($this->tid(), $this->token);
        $doc = $this->carica('scansione 12', 'casa', 'Luce Demo: bolletta della luce');
        $this->assertSulMac($doc['id']);
        $vecchia = $this->lavoroDelMac($access);
        // Mentre il Mac lavora arriva un documento nuovo: gli esempi delle categorie, e quindi la domanda, cambiano.
        $this->carica('Bolletta gas aprile', 'bollette', 'Gas Demo, fattura gas');
        $this->request('POST', "/api/ai-connector-plugin/lavori/{$vecchia['id']}/risultato", ['risposta' => '{"categoria":"Casa"}', 'modello' => 'qwen3:4b-instruct-2507-q4_K_M'], $access);
        $this->assertStatus(409);
        $nuova = $this->lavoroDelMac($access);
        self::assertSame($vecchia['id'], $nuova['id'], 'stesso lavoro, domanda nuova');
        self::assertStringContainsString('Bolletta gas aprile', $nuova['prompt']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT tentativi FROM lavoro_ai'), 'non è un errore del Mac');
    }

    public function testWhenTheMacDisappearsTheServerMakesTheProposal(): void
    {
        $access = $this->macOnline($this->tid(), $this->token);
        $doc = $this->carica('scansione 12', 'casa', 'Luce Demo: bolletta della luce');
        $this->assertSulMac($doc['id']);
        $this->rispondi($access, $this->lavoroDelMac($access), ['categoria' => 'Bollette']);
        // Il Mac si spegne a metà catena: la richiesta dopo la fa il server, senza aspettare.
        $this->em()->getConnection()->executeStatement("UPDATE connettore_ai SET ultimo_contatto = NOW() - INTERVAL '5 minutes'");
        $this->classifier()->risposta = 'Bollette';
        self::assertSame('Bollette', $this->proponi($doc['id'])['nome']);
        self::assertCount(1, $this->classifier()->calls);
        self::assertSame(0, $this->lavori());
    }

    public function testTwoErrorsOnTheMacSendTheProposalToTheServer(): void
    {
        $access = $this->macOnline($this->tid(), $this->token);
        $doc = $this->carica('scansione 12', 'casa', 'Luce Demo: bolletta della luce');
        $this->assertSulMac($doc['id']);
        foreach (['timeout', 'memoria'] as $codice) {
            $l = $this->lavoroDelMac($access);
            $this->request('POST', "/api/ai-connector-plugin/lavori/{$l['id']}/errore", ['codice' => $codice], $access);
            $this->assertStatus(200);
        }
        self::assertSame('al_server', $this->em()->getConnection()->fetchOne('SELECT stato FROM lavoro_ai'));
        self::assertNull($this->lavoroDelMac($access));
        $this->classifier()->risposta = 'Bollette';
        self::assertSame('Bollette', $this->proponi($doc['id'])['nome']);
        self::assertSame(0, $this->lavori());
    }

    private function assertSulMac(string $id): void
    {
        $esito = $this->request('POST', "/api/tenants/{$this->tid()}/documenti/$id/proposta-categoria", [], $this->token);
        $this->assertStatus(429);
        self::assertSame('4', $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('Il Mac della famiglia sta analizzando il documento', (string) json_encode($esito, \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $lavoro
     * @param array<string, mixed> $risposta
     */
    private function rispondi(string $access, ?array $lavoro, array $risposta): void
    {
        self::assertNotNull($lavoro);
        $r = $this->request('POST', "/api/ai-connector-plugin/lavori/{$lavoro['id']}/risultato", ['risposta' => json_encode($risposta), 'modello' => 'qwen3:4b-instruct-2507-q4_K_M', 'durataMs' => 2000], $access);
        $this->assertStatus(200);
        self::assertSame(['voci' => 1], $r);
    }

    private function lavori(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM lavoro_ai');
    }

    private function carica(string $titolo, string $categoria, ?string $testo): array
    {
        [$contenuto, $nome] = null === $testo ? [$this->fixture('ocr_fattura.png'), 'scan.png'] : [$testo, 'scan.txt'];
        $doc = (array) $this->upload($this->tid(), $this->token, $contenuto, $nome, ['titolo' => $titolo, 'categoriaId' => (string) $this->categoria($categoria)->getId()]);
        $this->assertStatus(201);
        if (null !== $testo) {
            static::getContainer()->get(OcrDocumentoHandler::class)(new OcrDocumentoMessage($doc['id']));
        }

        return $doc;
    }

    /**
     * @param list<string> $nuove
     * @param list<string> $escludi
     *
     * @return array<string, mixed>
     */
    private function proponi(string $id, array $nuove = [], array $escludi = []): array
    {
        $esito = (array) $this->request('POST', "/api/tenants/{$this->tid()}/documenti/$id/proposta-categoria", ['nuove' => $nuove, 'escludi' => $escludi], $this->token);
        $this->assertStatus(200);

        return $esito;
    }

    private function assertOccupato(string $id, string $motivo): void
    {
        $esito = $this->request('POST', "/api/tenants/{$this->tid()}/documenti/$id/proposta-categoria", [], $this->token);
        $this->assertStatus(429);
        self::assertSame('5', $this->client->getResponse()->headers->get('Retry-After'), 'Retry-After breve: il wizard riprova');
        self::assertStringContainsString($motivo, (string) json_encode($esito, \JSON_UNESCAPED_UNICODE));
    }

    /** Una seconda sessione del database, come quella di un'altra richiesta in corso. */
    private function altraConnessione(): Connection
    {
        return DriverManager::getConnection(static::getContainer()->get(Connection::class)->getParams());
    }

    private function classifier(): FakeClassifier
    {
        return static::getContainer()->get(FakeClassifier::class);
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }
}
