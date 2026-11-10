<?php

namespace App\Tests\Functional;

/**
 * Test di caratterizzazione (fase 0): fissano il comportamento ATTUALE della
 * struttura dell'ente (anni, sedi, gruppi, operatori, passaggio d'anno)
 * prima del refactoring. Se uno di questi test cambia, il comportamento è
 * cambiato: va deciso, non è un effetto collaterale.
 */
final class EnteStrutturaTest extends ApiTestCase
{
    private string $tid;
    private string $token;
    private string $maestraToken;
    private string $maestraMembership;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $segreteria = $this->createUser('segreteria@scuola.it');
        $maestra = $this->createUser('maestra@scuola.it');
        $ente = $this->request('POST', '/api/admin/enti', [
            'ente' => ['tipo' => 'scuola', 'denominazione' => 'Scuola Rossi', 'codiceFiscale' => '80012345676', 'codiceMeccanografico' => 'MIEE000002'],
            'notaVerifica' => 'Ente di prova per i test di caratterizzazione.',
            'proprietario' => ['email' => $segreteria->getEmail()],
            'staff' => [['email' => $maestra->getEmail()]],
        ], $this->login($admin));
        $this->assertStatus(201);
        $this->tid = $ente['id'];
        $membri = $this->request('GET', "/api/admin/tenants/{$this->tid}", token: $this->login($admin))['membri'];
        $this->maestraMembership = array_values(array_filter($membri, static fn ($m) => 'maestra@scuola.it' === $m['email']))[0]['membershipId'];
        $this->token = $this->login($segreteria);
        $this->maestraToken = $this->login($maestra);
    }

    public function testYearRolloverMovesChosenGroupsWithPupilsAndOperators(): void
    {
        $sede = $this->post('sedi', ['nome' => 'Plesso Verdi', 'indirizzo' => 'Via Verdi 1']);
        $terza = $this->post('gruppi', ['nome' => '3ªB', 'sedeId' => $sede['id']]);
        $quinta = $this->post('gruppi', ['nome' => '5ªA']);
        $volley = $this->post('gruppi', ['nome' => 'Minivolley', 'tipo' => 'attivita']);
        $this->request('PUT', $this->uri("gruppi/{$terza['id']}/operatori"), ['membershipIds' => [$this->maestraMembership]], $this->token);
        $this->assertStatus(200);
        $leo = $this->post('iscritti', ['nome' => 'Leo', 'cognome' => 'Rossi', 'gruppiIds' => [$terza['id']]]);
        $this->post('iscritti', ['nome' => 'Sara', 'cognome' => 'Bianchi', 'gruppiIds' => [$quinta['id']]]);
        $this->post('iscritti', ['nome' => 'Ugo', 'cognome' => 'Neri', 'gruppiIds' => [$volley['id']]]);

        $anni = $this->request('GET', $this->uri('anni'), token: $this->token);
        self::assertCount(1, $anni);
        $vecchio = $anni[0];
        self::assertFalse($vecchio['archiviato']);

        // Validazioni del passaggio.
        $this->request('POST', $this->uri("anni/{$vecchio['id']}/passaggio"), ['gruppi' => []], $this->token);
        $this->assertStatus(422);
        $this->request('POST', $this->uri("anni/{$vecchio['id']}/passaggio"), ['nome' => $vecchio['nome']], $this->token);
        $this->assertStatus(409);
        $this->request('POST', $this->uri("anni/{$vecchio['id']}/passaggio"), ['nome' => '2099/00'], $this->maestraToken);
        $this->assertStatus(403);

        // 3ªB → 4ªB (con iscritti e operatori), la 5ª esce, il Minivolley prosegue.
        $nuovo = $this->request('POST', $this->uri("anni/{$vecchio['id']}/passaggio"), ['nome' => '2099/00', 'gruppi' => [
            ['id' => $terza['id'], 'nuovoNome' => '4ªB'],
            ['id' => $quinta['id'], 'nuovoNome' => null],
            ['id' => $volley['id'], 'nuovoNome' => 'Minivolley'],
        ]], $this->token);
        $this->assertStatus(201);
        self::assertSame(['2099/00', false], [$nuovo['nome'], $nuovo['archiviato']]);
        // Anche con la stessa data di creazione (al secondo) il più recente viene prima.
        $this->em()->getConnection()->executeStatement('UPDATE ente_anno SET created_at = (SELECT MIN(created_at) FROM ente_anno)');
        $anni = $this->request('GET', $this->uri('anni'), token: $this->token);
        self::assertSame(['2099/00' => false, $vecchio['nome'] => true], array_column($anni, 'archiviato', 'nome'));

        $correnti = $this->request('GET', $this->uri('gruppi'), token: $this->token);
        self::assertSame(['4ªB', 'Minivolley'], array_column($correnti, 'nome'));
        $quarta = $correnti[0];
        self::assertSame([1, 'classe', 'Plesso Verdi', 'Nome Cognome'], [$quarta['iscritti'], $quarta['tipo'], $quarta['sede']['nome'], $quarta['operatori'][0]['nome']]);
        self::assertSame('attivita', $correnti[1]['tipo']);
        self::assertSame(['Leo'], array_column($this->request('GET', $this->uri("iscritti?gruppo={$quarta['id']}"), token: $this->token)['iscritti'], 'nome'));

        // L'anno archiviato resta consultabile, in sola lettura.
        $archiviati = $this->request('GET', $this->uri("gruppi?anno={$vecchio['id']}"), token: $this->token);
        self::assertSame(['3ªB', '5ªA', 'Minivolley'], array_column($archiviati, 'nome'));
        self::assertTrue($archiviati[0]['archiviato']);
        $this->request('PUT', $this->uri("gruppi/{$terza['id']}"), ['nome' => 'X'], $this->token);
        $this->assertStatus(409);
        $this->request('DELETE', $this->uri("gruppi/{$quinta['id']}"), token: $this->token);
        $this->assertStatus(409);
        $this->request('POST', $this->uri("anni/{$vecchio['id']}/passaggio"), ['nome' => '2100/01'], $this->token);
        $this->assertStatus(409);
        self::assertNotEmpty($this->auditRows('ente.passaggio_anno'));
        self::assertSame('Leo', $leo['nome']);
    }

    public function testBranchesGroupsAndOperatorScope(): void
    {
        $sede = $this->post('sedi', ['nome' => 'Plesso A']);
        $this->request('POST', $this->uri('sedi'), ['indirizzo' => 'senza nome'], $this->token);
        $this->assertStatus(422);
        $aggiornata = $this->request('PUT', $this->uri("sedi/{$sede['id']}"), ['nome' => 'Plesso Alfa', 'indirizzo' => 'Via Roma 2'], $this->token);
        self::assertSame(['Plesso Alfa', 'Via Roma 2'], [$aggiornata['nome'], $aggiornata['indirizzo']]);
        self::assertSame(['Plesso Alfa'], array_column($this->request('GET', $this->uri('sedi'), token: $this->token), 'nome'));

        $gruppo = $this->post('gruppi', ['nome' => '1ªA', 'sedeId' => $sede['id']]);
        $vuoto = $this->post('gruppi', ['nome' => 'Coro', 'tipo' => 'attivita']);
        $this->request('POST', $this->uri('gruppi'), ['nome' => 'X', 'tipo' => 'squadra'], $this->token);
        $this->assertStatus(422);
        $modificato = $this->request('PUT', $this->uri("gruppi/{$gruppo['id']}"), ['nome' => '1ªA bis', 'tipo' => 'classe'], $this->token);
        self::assertSame(['1ªA bis', null], [$modificato['nome'], $modificato['sede']], 'senza sedeId la sede si toglie');

        // Gruppo con iscritti: non si elimina; vuoto sì.
        $this->post('iscritti', ['nome' => 'Leo', 'cognome' => 'Rossi', 'gruppiIds' => [$gruppo['id']]]);
        $this->request('DELETE', $this->uri("gruppi/{$gruppo['id']}"), token: $this->token);
        $this->assertStatus(409);
        $this->request('DELETE', $this->uri("gruppi/{$vuoto['id']}"), token: $this->token);
        $this->assertStatus(204);

        // Eliminare una sede usata da un gruppo la toglie dal gruppo.
        $this->request('PUT', $this->uri("gruppi/{$gruppo['id']}"), ['nome' => '1ªA', 'sedeId' => $sede['id']], $this->token);
        $this->request('DELETE', $this->uri("sedi/{$sede['id']}"), token: $this->token);
        $this->assertStatus(204);
        self::assertNull($this->request('GET', $this->uri('gruppi'), token: $this->token)[0]['sede']);

        // Operatori: solo membri attivi dell'ente.
        $this->request('PUT', $this->uri("gruppi/{$gruppo['id']}/operatori"), ['membershipIds' => [(string) \Symfony\Component\Uid\Uuid::v7()]], $this->token);
        $this->assertStatus(422);

        // Lo staff non modifica la struttura e vede solo i suoi gruppi.
        $this->request('POST', $this->uri('gruppi'), ['nome' => 'Mio'], $this->maestraToken);
        $this->assertStatus(403);
        self::assertSame([], $this->request('GET', $this->uri('gruppi'), token: $this->maestraToken));
        $this->request('PUT', $this->uri("gruppi/{$gruppo['id']}/operatori"), ['membershipIds' => [$this->maestraMembership]], $this->token);
        self::assertSame(['1ªA'], array_column($this->request('GET', $this->uri('gruppi'), token: $this->maestraToken), 'nome'));
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): array
    {
        $r = $this->request('POST', $this->uri($path), $body, $this->token);
        $this->assertStatus(201);

        return $r;
    }

    private function uri(string $path): string
    {
        return "/api/enti/{$this->tid}/$path";
    }
}
