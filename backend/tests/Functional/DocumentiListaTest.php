<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;

/**
 * Lista dei documenti: paginazione con il totale nell'header X-Total-Count,
 * ordinamento per colonna, operazioni di gruppo (sposta, elimina) con gli
 * stessi permessi delle operazioni singole.
 */
final class DocumentiListaTest extends ApiTestCase
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

    public function testPaginationTotalAndSorting(): void
    {
        $token = $this->login($this->owner);
        foreach (['Bolletta gas', 'Assicurazione auto', 'Contratto affitto'] as $i => $titolo) {
            $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png').str_repeat(' ', $i), $titolo.'.png', ['categoriaId' => $this->cat('casa'), 'titolo' => $titolo]);
            $this->assertStatus(201);
        }

        $pagina = $this->elenco($token, 'itemsPerPage=2&page=1&ordina=titolo&verso=asc');
        self::assertSame('3', $pagina['totale']);
        self::assertSame(['Assicurazione auto', 'Bolletta gas'], array_column($pagina['righe'], 'titolo'));
        $seconda = $this->elenco($token, 'itemsPerPage=2&page=2&ordina=titolo&verso=asc');
        self::assertSame(['Contratto affitto'], array_column($seconda['righe'], 'titolo'));
        self::assertSame('Contratto affitto', $this->elenco($token, 'ordina=titolo&verso=desc')['righe'][0]['titolo']);

        // Il totale segue i filtri.
        self::assertSame('1', $this->elenco($token, 'q=bolletta')['totale']);
        // Visibile anche al browser (CORS).
        $this->client->request('OPTIONS', "/api/tenants/{$this->tid()}/documenti", server: ['HTTP_ORIGIN' => 'http://localhost:5000', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
        $this->client->request('GET', "/api/tenants/{$this->tid()}/documenti", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ORIGIN' => 'http://localhost:5000']);
        self::assertStringContainsStringIgnoringCase('X-Total-Count', (string) $this->client->getResponse()->headers->get('Access-Control-Expose-Headers'));
    }

    public function testGroupMoveAndDeleteRespectPermissions(): void
    {
        $ownerToken = $this->login($this->owner);
        $memberToken = $this->login($this->member);
        $mio = $this->upload($this->tid(), $memberToken, $this->fixture('ocr_fattura.png'), 'mio.png', ['categoriaId' => $this->cat('casa')]);
        $altrui = $this->upload($this->tid(), $ownerToken, $this->fixture('ocr_fattura.png').' ', 'altrui.png', ['categoriaId' => $this->cat('casa')]);

        // Spostare in una categoria sensibile cifra il documento, come il cambio singolo.
        $esito = $this->request('POST', $this->uriGruppo(), ['azione' => 'sposta', 'ids' => [$mio['id'], $altrui['id'], 'non-esiste'], 'categoriaId' => $this->cat('salute')], $memberToken);
        $this->assertStatus(200);
        self::assertSame(1, $esito['fatti']);
        self::assertSame(['non hai il permesso', 'documento non trovato'], array_column($esito['saltati'], 'motivo'));
        $dopo = $this->request('GET', "/api/tenants/{$this->tid()}/documenti/{$mio['id']}", token: $memberToken);
        self::assertSame('Salute', $dopo['categoria']['nome']);
        self::assertTrue($dopo['cifrato']);

        $ripetuto = $this->request('POST', $this->uriGruppo(), ['azione' => 'sposta', 'ids' => [$mio['id']], 'categoriaId' => $this->cat('salute')], $memberToken);
        self::assertSame('è già in questa categoria', $ripetuto['saltati'][0]['motivo']);

        // Elimina: il proprietario della famiglia può tutto.
        $esito = $this->request('POST', $this->uriGruppo(), ['azione' => 'elimina', 'ids' => [$mio['id'], $altrui['id']]], $ownerToken);
        self::assertSame(2, $esito['fatti']);
        self::assertSame('0', $this->elenco($ownerToken, '')['totale']);

        $this->request('POST', $this->uriGruppo(), ['azione' => 'archivia', 'ids' => [$mio['id']]], $ownerToken);
        $this->assertStatus(422);
        $estraneo = $this->login($this->createUser('estraneo@example.com'));
        $this->request('POST', $this->uriGruppo(), ['azione' => 'elimina', 'ids' => [$mio['id']]], $estraneo);
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
    }

    /** @return array{totale: ?string, righe: list<array<string, mixed>>} */
    private function elenco(string $token, string $query): array
    {
        $righe = $this->request('GET', "/api/tenants/{$this->tid()}/documenti?$query", token: $token);
        $this->assertStatus(200);

        return ['totale' => $this->client->getResponse()->headers->get('X-Total-Count'), 'righe' => $righe];
    }

    private function uriGruppo(): string
    {
        return "/api/tenants/{$this->tid()}/documenti/gruppo";
    }

    private function cat(string $slug): string
    {
        return (string) $this->categoria($slug)->getId();
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }
}
