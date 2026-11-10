<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Documenti\Entity\Documento;
use App\Documenti\Indice\DatiEstratti;
use App\Documenti\Indice\IndiceDocumenti;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\Uid\Uuid;

/**
 * Filtri per anno, mittente, tag e tipo di file e relativi conteggi, per il
 * pannello dei filtri e la vista a gruppi.
 */
final class DocumentiFaccetteTest extends ApiTestCase
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

    public function testCountsAndFiltersWorkTogether(): void
    {
        $this->documento('a', 'bollette', '2024-03-01', 'EDILNORD S.R.L.', ['luce']);
        $this->documento('b', 'bollette', '2024-07-01', 'Edilnord srl', ['luce', 'casa']);
        $this->documento('c', 'casa', '2023-01-15', 'Comune di Esempio', ['tasse']);
        $this->documento('d', 'casa', null, null, []);

        $f = $this->faccette('');
        self::assertSame(4, $f['totale']);
        self::assertSame([['2024', 2], ['2023', 1], ['__nessuno__', 1]], $this->coppie($f['anni']));
        self::assertSame([['edilnord', 2], ['comune di esempio', 1], ['__nessuno__', 1]], $this->coppie($f['emittenti']), 'lo stesso mittente scritto in due modi è uno solo');
        self::assertSame([['luce', 2], ['casa', 1], ['tasse', 1], ['__nessuno__', 1]], $this->coppie($f['tag']));
        self::assertSame([['Foto', 4]], $this->coppie($f['tipi']));

        // Ogni filtro restringe la lista e gli altri conteggi, non il proprio.
        $conFiltro = $this->faccette('anno=2024');
        self::assertSame(2, $conFiltro['totale']);
        self::assertCount(3, $conFiltro['anni'], 'gli altri anni restano sceglibili');
        self::assertSame([['edilnord', 2]], $this->coppie($conFiltro['emittenti']));

        self::assertSame(['a', 'b'], $this->titoli('emittente=edilnord'));
        self::assertSame(['d'], $this->titoli('emittente=__nessuno__'));
        self::assertSame(['b'], $this->titoli('tag=casa'));
        self::assertSame(['d'], $this->titoli('tag=__nessuno__'));
        self::assertSame(['c'], $this->titoli('anno=2023'));
        self::assertSame(['d'], $this->titoli('anno=__nessuno__'));
        self::assertSame(['a'], $this->titoli('anno=2024&tag=luce&ordina=data&verso=asc&itemsPerPage=1'));
        self::assertSame([], $this->titoli('tipo=PDF'));
        self::assertSame(['a', 'b', 'c', 'd'], $this->titoli('tipo=Foto'));
        self::assertSame([], $this->titoli('emittente=non+esiste'));
    }

    public function testEncryptedDocumentsHaveNoIndexButAreCounted(): void
    {
        $this->documento('chiaro', 'casa', '2024-01-01', 'Enel', ['luce']);
        $this->upload((string) $this->tenant->getId(), $this->token, $this->fixture('ocr_fattura.png').' ', 'referto.png', ['categoriaId' => (string) $this->categoria('salute')->getId(), 'titolo' => 'referto']);
        $this->assertStatus(201);

        $f = $this->faccette('');
        self::assertSame([['enel', 1], ['__nessuno__', 1]], $this->coppie($f['emittenti']));
        self::assertSame(['referto'], $this->titoli('emittente=__nessuno__'));
    }

    public function testCountsRespectRestrictedAccessAndTenant(): void
    {
        $this->documento('bolletta', 'bollette', '2024-01-01', 'Enel', ['luce']);
        $this->documento('segreto', 'casa', '2020-01-01', 'Notaio Bianchi', ['eredita']);

        // Un membro con accesso limitato alle bollette non deve sapere del resto.
        $limitato = $this->createUser('limitato@example.com');
        $this->em()->persist(Membership::perEmergenza($limitato, $this->em()->find(Tenant::class, $this->tenant->getId()), $this->systemRole(Role::EMERGENZA), [(string) $this->categoria('bollette')->getId()]));
        $this->em()->flush();
        $this->client->request('GET', "/api/tenants/{$this->tenant->getId()}/documenti/faccette", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($limitato)]);
        $this->assertStatus(200);
        $f = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $f['totale']);
        self::assertSame([['enel', 1]], $this->coppie($f['emittenti']));
        self::assertSame([['luce', 1]], $this->coppie($f['tag']));
        self::assertSame([['2024', 1]], $this->coppie($f['anni']));

        // Chi non è della famiglia non ottiene nulla.
        $altro = $this->createUser('altro@example.com');
        $this->addMember($altro, $this->createTenant('Altri'), Role::OWNER);
        $this->client->request('GET', "/api/tenants/{$this->tenant->getId()}/documenti/faccette", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($altro)]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
    }

    /** @param list<string> $tag */
    private function documento(string $titolo, string $categoria, ?string $data, ?string $emittente, array $tag): void
    {
        static $n = 0;
        $doc = $this->upload((string) $this->tenant->getId(), $this->token, $this->fixture('ocr_fattura.png').str_repeat(' ', ++$n), $titolo.'.png', ['categoriaId' => (string) $this->categoria($categoria)->getId(), 'titolo' => $titolo]);
        $this->assertStatus(201);
        $this->em()->clear();
        $entity = $this->em()->find(Documento::class, Uuid::fromString($doc['id']));
        $entity->setDataDocumento(null === $data ? null : new \DateTimeImmutable($data));
        static::getContainer()->get(IndiceDocumenti::class)->salva($entity, new DatiEstratti(emittente: $emittente, tag: $tag), 'm', null, []);
        $this->em()->flush();
    }

    /** @return array<string, mixed> */
    private function faccette(string $parametri): array
    {
        $this->client->request('GET', "/api/tenants/{$this->tenant->getId()}/documenti/faccette?{$parametri}", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        $this->assertStatus(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return list<string> titoli in ordine alfabetico */
    private function titoli(string $parametri): array
    {
        $this->client->request('GET', "/api/tenants/{$this->tenant->getId()}/documenti?{$parametri}".(str_contains($parametri, 'ordina') ? '' : '&ordina=titolo&verso=asc'), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        $this->assertStatus(200);

        return array_column(json_decode((string) $this->client->getResponse()->getContent(), true), 'titolo');
    }

    /**
     * @param list<array{valore: string, n: int}> $voci
     *
     * @return list<array{0: string, 1: int}>
     */
    private function coppie(array $voci): array
    {
        return array_map(static fn (array $v) => [$v['valore'], $v['n']], $voci);
    }
}
