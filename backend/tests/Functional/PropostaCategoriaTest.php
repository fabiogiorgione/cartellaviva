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
        self::assertSame([['categoria' => 'Bollette', 'descrizione' => 'documenti di tipo Bollette']], $this->classifier()->callsVerifica, 'la scelta si verifica contro la descrizione della categoria');
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
