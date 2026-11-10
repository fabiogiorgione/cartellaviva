<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;

/**
 * Membri della famiglia senza account e categorie (di sistema e personalizzate).
 */
final class FamigliaCategorieTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
        $this->member = $this->createUser('membro@example.com');
        $this->addMember($this->member, $this->tenant, Role::MEMBER);
    }

    public function testMinorFamilyMemberWithoutAccount(): void
    {
        $token = $this->login($this->member);
        $milano = $this->em()->getRepository(\App\Account\Entity\Geo\GeoComune::class)->findOneBy(['registryCode' => 'F205', 'status' => 'active']);
        $figlio = $this->request('POST', $this->uri('membri_famiglia'), [
            'nome' => 'Luca', 'cognome' => 'Rossi', 'sesso' => 'm', 'dataNascita' => '2018-04-12', 'relazione' => 'figlio',
            'nazioneNascita' => 100, 'comuneNascita' => $milano->getId(),
        ], $token);

        $this->assertStatus(201);
        self::assertSame('figlio', $figlio['relazione']);
        self::assertSame('2018-04-12', $figlio['dataNascita']);

        // Codice fiscale incoerente con i dati: rifiutato (la verifica è la stessa dell'anagrafica dell'account).
        $this->request('PATCH', $this->uri('membri_famiglia/'.$figlio['id']), ['codiceFiscale' => 'RSSMRA80A01H501U'], $token, 'application/merge-patch+json');
        $this->assertStatus(422);
        $body = (string) $this->client->getResponse()->getContent();
        self::assertContains('codiceFiscale', array_column(json_decode($body, true)['violations'] ?? [], 'propertyPath'), $body);

        // Quello corretto (calcolato dai dati) è accettato: nessun limite di età per i membri.
        $cf = static::getContainer()->get(\App\Account\Anagrafica\CodiceFiscaleService::class)->calculate('Luca', 'Rossi', new \DateTimeImmutable('2018-04-12'), 'm', 'F205');
        $ok = $this->request('PATCH', $this->uri('membri_famiglia/'.$figlio['id']), ['codiceFiscale' => $cf], $token, 'application/merge-patch+json');
        $this->assertStatus(200);
        self::assertSame($cf, $ok['codiceFiscale']);

        $this->request('POST', $this->uri('membri_famiglia'), ['relazione' => 'figlia'], $token);
        $this->assertStatus(422);
    }

    public function testCodiceFiscaleUniquePerTenantNotGlobally(): void
    {
        $cf = ['nome' => 'Anna', 'codiceFiscale' => 'RSSNNA18D52F205M', 'codiceFiscaleManuale' => true];
        $this->request('POST', $this->uri('membri_famiglia'), $cf, $this->login($this->owner));
        $this->assertStatus(201);
        $this->request('POST', $this->uri('membri_famiglia'), $cf, $this->login($this->owner));
        $this->assertStatus(422);

        // Stessa bambina nel tenant dell'altro genitore: ammessa.
        $altroGenitore = $this->createUser('papa@example.com');
        $altroTenant = $this->createTenant('Famiglia Bianchi');
        $this->addMember($altroGenitore, $altroTenant, Role::OWNER);
        $this->request('POST', "/api/tenants/{$altroTenant->getId()}/membri_famiglia", $cf, $this->login($altroGenitore));
        $this->assertStatus(201);
    }

    public function testViewerReadsButCannotManageFamilyMembers(): void
    {
        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $token = $this->login($viewer);

        $this->request('GET', $this->uri('membri_famiglia'), token: $token);
        $this->assertStatus(200);
        $this->request('POST', $this->uri('membri_famiglia'), ['nome' => 'X'], $token);
        $this->assertStatus(403);
    }

    public function testDocumentForSeveralMembersAndOnlyMembersOfTheSameTenant(): void
    {
        $token = $this->login($this->member);
        $a = $this->request('POST', $this->uri('membri_famiglia'), ['nome' => 'Luca'], $token);
        $b = $this->request('POST', $this->uri('membri_famiglia'), ['nome' => 'Sara'], $token);

        $doc = $this->upload((string) $this->tenant->getId(), $token, $this->fixture('ocr_fattura.png'), 'iscrizione.png', [
            'categoriaId' => (string) $this->categoria('scuola')->getId(),
            'membriIds' => [$a['id'], $b['id']],
        ]);
        $this->assertStatus(201);
        self::assertEqualsCanonicalizing(['Luca', 'Sara'], array_column($doc['membri'], 'nome'));

        $filtered = $this->request('GET', $this->uri('documenti?membroId='.$b['id']), token: $token);
        self::assertSame([$doc['id']], array_column($filtered, 'id'));

        // Membro di un altro tenant: rifiutato.
        $altro = $this->createUser('luca@example.com');
        $altroTenant = $this->createTenant('Famiglia Bianchi');
        $this->addMember($altro, $altroTenant, Role::OWNER);
        $estraneo = $this->request('POST', "/api/tenants/{$altroTenant->getId()}/membri_famiglia", ['nome' => 'Estraneo'], $this->login($altro));
        $this->upload((string) $this->tenant->getId(), $token, $this->fixture('ocr_fattura.png'), 'x.png', [
            'categoriaId' => (string) $this->categoria('scuola')->getId(),
            'membriIds' => [$estraneo['id']],
        ]);
        $this->assertStatus(422);
    }

    public function testCategories(): void
    {
        $memberToken = $this->login($this->member);
        $categorie = $this->request('GET', $this->uri('categorie'), token: $memberToken);
        $this->assertStatus(200);
        self::assertCount(9, $categorie);
        $sensibili = array_column(array_filter($categorie, static fn ($c) => $c['isSensibile']), 'nome');
        self::assertEqualsCanonicalizing(['Documenti Identità', 'Salute', 'Farmaci', 'Da categorizzare', 'Condivisi con enti'], $sensibili);

        // Il member non gestisce le categorie; l'owner sì.
        $this->request('POST', $this->uri('categorie'), ['nome' => 'Sport', 'icona' => 'trophy', 'colore' => '#10b981'], $memberToken);
        $this->assertStatus(403);
        $ownerToken = $this->login($this->owner);
        $custom = $this->request('POST', $this->uri('categorie'), ['nome' => 'Psicologo', 'icona' => 'brain', 'colore' => '#7c3aed', 'isSensibile' => true], $ownerToken);
        $this->assertStatus(201);
        self::assertFalse($custom['sistema']);

        // Categorie di sistema: non modificabili.
        $salute = array_values(array_filter($categorie, static fn ($c) => 'Salute' === $c['nome']))[0];
        $this->request('PATCH', $this->uri('categorie/'.$salute['id']), ['isSensibile' => false], $ownerToken, 'application/merge-patch+json');
        $this->assertStatus(403);

        // Documento in categoria personalizzata sensibile: cifrato; la categoria non si cancella.
        $doc = $this->upload((string) $this->tenant->getId(), $ownerToken, $this->fixture('ocr_fattura.png'), 'seduta.png', ['categoriaId' => $custom['id']]);
        self::assertTrue($doc['cifrato']);
        $this->request('DELETE', $this->uri('categorie/'.$custom['id']), token: $ownerToken);
        $this->assertStatus(409);

        // Categoria personalizzata di un altro tenant: non utilizzabile qui.
        $altro = $this->createUser('luca@example.com');
        $altroTenant = $this->createTenant('Famiglia Bianchi');
        $this->addMember($altro, $altroTenant, Role::OWNER);
        $this->upload((string) $altroTenant->getId(), $this->login($altro), $this->fixture('ocr_fattura.png'), 'x.png', ['categoriaId' => $custom['id']]);
        $this->assertStatus(422);
    }

    public function testDeleteCategoryMovingDocumentsAndCounts(): void
    {
        $ownerToken = $this->login($this->owner);
        $sport = $this->request('POST', $this->uri('categorie'), ['nome' => 'Sport', 'icona' => 'trophy', 'colore' => '#10b981'], $ownerToken);
        $casa = $this->request('POST', $this->uri('categorie'), ['nome' => 'Casa e mutuo', 'icona' => 'house', 'colore' => '#275ac5'], $ownerToken);
        $doc = $this->upload((string) $this->tenant->getId(), $ownerToken, $this->fixture('ocr_fattura.png'), 'iscrizione.png', ['categoriaId' => $sport['id']]);

        // Conteggi nell'elenco (anche per chi non gestisce le categorie).
        $perId = array_column($this->request('GET', $this->uri('categorie'), token: $this->login($this->member)), 'documenti', 'id');
        self::assertSame(1, $perId[$sport['id']]);
        self::assertSame(0, $perId[$casa['id']]);

        // Con documenti serve la destinazione, diversa e della famiglia.
        $this->request('DELETE', $this->uri('categorie/'.$sport['id']), token: $ownerToken);
        $this->assertStatus(409);
        $this->request('DELETE', $this->uri('categorie/'.$sport['id']).'?spostaIn='.$sport['id'], token: $ownerToken);
        $this->assertStatus(422);
        $this->request('DELETE', $this->uri('categorie/'.$sport['id']).'?spostaIn='.$casa['id'], token: $ownerToken);
        $this->assertStatus(204);

        self::assertSame($casa['id'], $this->request('GET', $this->uri('documenti/'.$doc['id']), token: $ownerToken)['categoria']['id']);

        // Verso una categoria sensibile, come nel cambio di categoria: il documento si cifra.
        $intima = $this->request('POST', $this->uri('categorie'), ['nome' => 'Riservata', 'icona' => 'lock', 'colore' => '#64748b', 'isSensibile' => true], $ownerToken);
        $this->request('DELETE', $this->uri('categorie/'.$casa['id']).'?spostaIn='.$intima['id'], token: $ownerToken);
        $this->assertStatus(204);
        self::assertTrue($this->request('GET', $this->uri('documenti/'.$doc['id']), token: $ownerToken)['cifrato']);
        $nomi = array_column($this->request('GET', $this->uri('categorie'), token: $ownerToken), 'nome');
        self::assertNotContains('Sport', $nomi);
        self::assertNotEmpty($this->auditRows('categoria.deleted'));

        // Vuota: si elimina senza destinazione.
        $this->request('DELETE', $this->uri('categorie/'.$this->request('POST', $this->uri('categorie'), ['nome' => 'Vuota', 'icona' => 'folder', 'colore' => '#64748b'], $ownerToken)['id']), token: $ownerToken);
        $this->assertStatus(204);
    }

    private function uri(string $path): string
    {
        return "/api/tenants/{$this->tenant->getId()}/$path";
    }
}
