<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;

/**
 * Fase 7.2: condivisione esterna con link + codice, scadenza, limite di
 * download, blocco dopo 5 codici errati, revoca, permessi.
 */
final class CondivisioneTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;
    private User $membro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
        $this->membro = $this->createUser('membro@example.com');
        $this->addMember($this->membro, $this->tenant, Role::MEMBER);
    }

    public function testShareWithLinkAndCodeDecryptsOnlyOnDownload(): void
    {
        $token = $this->login($this->owner);
        $png = $this->fixture('ocr_fattura.png');
        $referto = $this->upload($this->tid(), $token, $png, 'referto.png', ['categoriaId' => (string) $this->categoria('salute')->getId()]);
        self::assertTrue($referto['cifrato']);

        $c = $this->request('POST', "/api/tenants/{$this->tid()}/condivisioni", ['documentiIds' => [$referto['id']], 'ore' => 48, 'maxDownload' => 2, 'nota' => 'Dott. Bianchi'], $token);
        $this->assertStatus(201);
        self::assertMatchesRegularExpression('~/condivisione\?token=[A-Za-z0-9_-]{40,}$~', $c['url']);
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $c['codice']);
        $link = substr($c['url'], strpos($c['url'], 'token=') + 6);
        // Il codice si vede una volta sola.
        self::assertArrayNotHasKey('codice', $this->request('GET', "/api/tenants/{$this->tid()}/condivisioni", token: $token)[0]);

        // Anteprima pubblica: nessun titolo.
        $anteprima = $this->request('GET', "/api/condivisioni/$link");
        self::assertSame(['attiva', 1, 'Nome'], [$anteprima['stato'], $anteprima['documenti'], $anteprima['condivisoDa']]);
        self::assertStringNotContainsString('referto', json_encode($anteprima));

        $this->request('POST', "/api/condivisioni/$link/accedi", ['codice' => 'AAAA-AAAA']);
        $this->assertStatus(403);
        $this->mailer()->reset();
        $accesso = $this->request('POST', "/api/condivisioni/$link/accedi", ['codice' => strtolower($c['codice'])]);
        $this->assertStatus(200);
        self::assertSame('referto', $accesso['documenti'][0]['titolo']);
        $avviso = $this->ultimaEmail();
        self::assertSame('owner@example.com', $avviso->getTo()[0]->getAddress());
        self::assertStringNotContainsString('referto', (string) $avviso->getHtmlBody());
        self::assertStringNotContainsString('Bianchi', (string) $avviso->getHtmlBody());

        $url = "/api/condivisioni/$link/documenti/{$referto['id']}/file";
        $this->client->request('GET', $url);
        $this->assertStatus(403);
        $this->client->request('GET', $url, server: ['HTTP_X_CONDIVISIONE_SESSIONE' => $accesso['sessione']]);
        $this->assertStatus(200);
        self::assertSame($png, $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        // Un documento della famiglia non incluso nel link: no.
        $altro = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png').'1', 'altro.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->client->request('GET', "/api/condivisioni/$link/documenti/{$altro['id']}/file", server: ['HTTP_X_CONDIVISIONE_SESSIONE' => $accesso['sessione']]);
        $this->assertStatus(404);

        // Limite di download.
        $this->client->request('GET', $url, server: ['HTTP_X_CONDIVISIONE_SESSIONE' => $accesso['sessione']]);
        $this->assertStatus(200);
        $this->client->request('GET', $url, server: ['HTTP_X_CONDIVISIONE_SESSIONE' => $accesso['sessione']]);
        $this->assertStatus(410);
        $elenco = $this->request('GET', "/api/tenants/{$this->tid()}/condivisioni", token: $token)[0];
        self::assertSame(['esaurita', 2, 'Dott. Bianchi'], [$elenco['stato'], $elenco['download'], $elenco['nota']]);
        self::assertCount(2, $this->auditRows('documento.download_esterno'));
    }

    public function testWrongCodesBlockAndRevocationStopsAccess(): void
    {
        $token = $this->login($this->owner);
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'polizza.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $c = $this->request('POST', "/api/tenants/{$this->tid()}/condivisioni", ['documentiIds' => [$doc['id']]], $token);
        $link = substr($c['url'], strpos($c['url'], 'token=') + 6);
        for ($i = 0; $i < 5; ++$i) {
            $this->request('POST', "/api/condivisioni/$link/accedi", ['codice' => 'ZZZZ-ZZZZ']);
            $this->assertStatus(403);
        }
        // Bloccata: nemmeno il codice giusto apre più.
        $this->request('POST', "/api/condivisioni/$link/accedi", ['codice' => $c['codice']]);
        $this->assertStatus(410);

        $d = $this->request('POST', "/api/tenants/{$this->tid()}/condivisioni", ['documentiIds' => [$doc['id']]], $token);
        $link2 = substr($d['url'], strpos($d['url'], 'token=') + 6);
        $sessione = $this->request('POST', "/api/condivisioni/$link2/accedi", ['codice' => $d['codice']])['sessione'];
        $this->request('POST', "/api/tenants/{$this->tid()}/condivisioni/{$d['id']}/revoca", token: $token);
        $this->assertStatus(200);
        $this->client->request('GET', "/api/condivisioni/$link2/documenti/{$doc['id']}/file", server: ['HTTP_X_CONDIVISIONE_SESSIONE' => $sessione]);
        $this->assertStatus(410);
        self::assertSame('revocata', $this->request('GET', "/api/condivisioni/$link2")['stato']);
    }

    public function testPermissionsAndValidation(): void
    {
        $tOwner = $this->login($this->owner);
        $doc = $this->upload($this->tid(), $tOwner, $this->fixture('ocr_fattura.png'), 'polizza.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);

        // Il lettore non condivide.
        $lettore = $this->createUser('nonno@example.com');
        $this->addMember($lettore, $this->tenant, Role::VIEWER);
        $this->request('POST', "/api/tenants/{$this->tid()}/condivisioni", ['documentiIds' => [$doc['id']]], $this->login($lettore));
        $this->assertStatus(403);

        // Il membro condivide e vede solo le proprie; il proprietario le vede tutte e le revoca.
        $tMembro = $this->login($this->membro);
        $mia = $this->request('POST', "/api/tenants/{$this->tid()}/condivisioni", ['documentiIds' => [$doc['id']], 'ore' => 1], $tMembro);
        $this->assertStatus(201);
        $this->request('POST', "/api/tenants/{$this->tid()}/condivisioni", ['documentiIds' => [$doc['id']]], $tOwner);
        self::assertCount(1, $this->request('GET', "/api/tenants/{$this->tid()}/condivisioni", token: $tMembro));
        self::assertCount(2, $this->request('GET', "/api/tenants/{$this->tid()}/condivisioni", token: $tOwner));

        // Documenti di un'altra famiglia, durate e limiti fuori misura: 422.
        $altra = $this->createTenant('Famiglia Bianchi');
        $this->addMember($this->owner, $altra, Role::OWNER);
        $estraneo = $this->upload((string) $altra->getId(), $tOwner, $this->fixture('ocr_fattura.png'), 'x.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        foreach ([['documentiIds' => [$estraneo['id']]], ['documentiIds' => [$doc['id']], 'ore' => 721], ['documentiIds' => [$doc['id']], 'maxDownload' => 0], ['documentiIds' => []]] as $body) {
            $this->request('POST', "/api/tenants/{$this->tid()}/condivisioni", $body, $tOwner);
            $this->assertStatus(422);
        }

        // Chi esce dalla famiglia: i suoi link vengono revocati.
        $this->request('DELETE', "/api/tenants/{$this->tid()}/members/".$this->membershipId($this->membro), token: $tOwner);
        $link = substr($mia['url'], strpos($mia['url'], 'token=') + 6);
        self::assertSame('revocata', $this->request('GET', "/api/condivisioni/$link")['stato']);
    }

    private function membershipId(User $user): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT id FROM membership WHERE user_id = ? AND tenant_id = ?', [$user->getId()->toRfc4122(), $this->tenant->getId()->toRfc4122()]);
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }
}
