<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\EmergenzaService;
use App\Tenancy\Entity\ContattoEmergenza;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\Mime\Email;

/**
 * Fase 7.5: contatto fidato con account, richiesta con attesa annullabile,
 * concessione in sola lettura limitata alle categorie scelte, revoca.
 */
final class EmergenzaTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;
    private User $membro;
    private User $contatto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
        $this->membro = $this->createUser('membro@example.com');
        $this->addMember($this->membro, $this->tenant, Role::MEMBER);
        $this->contatto = $this->createUser('zia@example.com');
    }

    public function testFullFlowWithWaitObjectionGrantAndRevocation(): void
    {
        $tOwner = $this->login($this->owner);
        $png = $this->fixture('ocr_fattura.png');
        $referto = $this->upload($this->tid(), $tOwner, $png, 'referto.png', ['categoriaId' => (string) $this->categoria('salute')->getId()]);
        $bolletta = $this->upload($this->tid(), $tOwner, $png.'x', 'bolletta.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $scadenze = [];
        foreach ([
            'visita' => ['documentoId' => $referto['id']],
            'bolletta' => ['documentoId' => $bolletta['id']],
            'mutuo' => ['categoriaId' => (string) $this->categoria('casa')->getId()],
            'generica' => [],
        ] as $titolo => $extra) {
            $scadenze[$titolo] = $this->request('POST', "/api/tenants/{$this->tid()}/scadenze", ['titolo' => $titolo, 'data' => '2099-05-01', 'ricorrenza' => 'nessuna'] + $extra, $tOwner)['id'];
            $this->assertStatus(201);
        }

        // Solo i proprietari indicano i contatti.
        $this->request('POST', "/api/tenants/{$this->tid()}/emergenza/contatti", ['email' => 'zia@example.com'], $this->login($this->membro));
        $this->assertStatus(403);
        $this->mailer()->reset();
        $c = $this->request('POST', "/api/tenants/{$this->tid()}/emergenza/contatti", [
            'email' => 'Zia@Example.com', 'giorniAttesa' => 3, 'categorieIds' => [(string) $this->categoria('salute')->getId()],
        ], $tOwner);
        $this->assertStatus(201);
        $invito = $this->tokenDa($this->ultimaEmail());
        self::assertSame(['Famiglia Rossi', 'zia@example.com'], array_values(array_intersect_key($this->request('GET', "/api/emergenza/invito?token=$invito"), ['famiglia' => 1, 'email' => 1])));

        // Accetta solo l'account della stessa casella.
        $this->request('POST', '/api/emergenza/accetta', ['token' => $invito], $this->login($this->createUser('altro@example.com')));
        $this->assertStatus(409);
        $tZia = $this->login($this->contatto);
        $mio = $this->request('POST', '/api/emergenza/accetta', ['token' => $invito], $tZia);
        $this->assertStatus(200);
        self::assertTrue($mio['puoChiedere']);
        // Contatto attivo ma nessun accesso finché non lo chiede e passa l'attesa.
        $this->request('GET', "/api/tenants/{$this->tid()}/documenti", token: $tZia);
        $this->assertStatus(403);

        // Richiesta: avvisati proprietario e membro, che può opporsi.
        $this->mailer()->reset();
        $this->request('POST', "/api/emergenza/{$c['id']}/richiedi", ['motivo' => 'Mario è in ospedale'], $tZia);
        $this->assertStatus(200);
        $destinatari = array_map(static fn (Email $e) => $e->getTo()[0]->getAddress(), $this->mailer()->getEvents()->getMessages());
        sort($destinatari);
        self::assertSame(['membro@example.com', 'owner@example.com'], $destinatari);
        $this->request('POST', "/api/emergenza/{$c['id']}/richiedi", [], $tZia);
        $this->assertStatus(409);
        $this->request('POST', "/api/tenants/{$this->tid()}/emergenza/contatti/{$c['id']}/nega", token: $this->login($this->membro));
        $this->assertStatus(200);
        self::assertSame(0, $this->servizio()->eseguiDovute());

        // Nuova richiesta, nessuna opposizione: a fine attesa l'accesso è concesso.
        $this->request('POST', "/api/emergenza/{$c['id']}/richiedi", [], $tZia);
        self::assertSame(0, $this->servizio()->eseguiDovute(), 'prima della fine dell\'attesa non succede nulla');
        $this->scadiAttesa();
        self::assertSame(1, $this->servizio()->eseguiDovute());

        // Sola lettura e solo le categorie scelte.
        $tZia = $this->login($this->fresh($this->contatto));
        $visibili = array_column($this->request('GET', "/api/tenants/{$this->tid()}/documenti", token: $tZia), 'id');
        self::assertSame([$referto['id']], $visibili);
        $this->request('GET', "/api/tenants/{$this->tid()}/documenti/{$bolletta['id']}", token: $tZia);
        $this->assertStatus(403);
        $this->client->request('GET', "/api/tenants/{$this->tid()}/documenti/{$referto['id']}/file", server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tZia]);
        $this->assertStatus(200);
        $this->upload($this->tid(), $tZia, $png.'y', 'nuovo.png', ['categoriaId' => (string) $this->categoria('salute')->getId()]);
        $this->assertStatus(403);
        // Anche le scadenze legate ad altre categorie non esistono per lei (titoli dei documenti compresi).
        $sue = $this->request('GET', "/api/tenants/{$this->tid()}/scadenze", token: $tZia);
        self::assertEqualsCanonicalizing(['visita', 'generica'], array_column($sue, 'titolo'));
        self::assertNotContains('bolletta.png', array_column(array_filter(array_column($sue, 'documento')), 'titolo'));
        $this->request('GET', "/api/tenants/{$this->tid()}/scadenze/{$scadenze['bolletta']}", token: $tZia);
        $this->assertStatus(404);
        $this->request('GET', "/api/tenants/{$this->tid()}/scadenze/{$scadenze['mutuo']}", token: $tZia);
        $this->assertStatus(404);
        self::assertCount(4, $this->request('GET', "/api/tenants/{$this->tid()}/scadenze", token: $tOwner), 'il proprietario le vede tutte');
        // Nemmeno le operazioni di gruppo rivelano il titolo di un documento nascosto.
        $esito = $this->request('POST', "/api/tenants/{$this->tid()}/documenti/gruppo", ['azione' => 'elimina', 'ids' => [$bolletta['id'], $referto['id']]], $tZia);
        self::assertSame(0, $esito['fatti']);
        self::assertSame([
            ['id' => $bolletta['id'], 'titolo' => null, 'motivo' => 'documento non trovato'],
            ['id' => $referto['id'], 'titolo' => $referto['titolo'], 'motivo' => 'non hai il permesso'],
        ], $esito['saltati']);

        // Il proprietario revoca: l'accesso si chiude subito.
        $this->request('POST', "/api/tenants/{$this->tid()}/emergenza/contatti/{$c['id']}/revoca", token: $tOwner);
        $this->assertStatus(200);
        $this->request('GET', "/api/tenants/{$this->tid()}/documenti", token: $tZia);
        $this->assertStatus(403);
        self::assertSame([], $this->request('GET', '/api/emergenza', token: $tZia));
        self::assertNotEmpty($this->auditRows('emergenza.accesso_concesso'));
    }

    public function testObjectionFromEmailLinkAndRoleNotAssignableByHand(): void
    {
        $tOwner = $this->login($this->owner);
        $c = $this->request('POST', "/api/tenants/{$this->tid()}/emergenza/contatti", ['email' => 'zia@example.com'], $tOwner);
        $invito = $this->tokenDa($this->ultimaEmail());
        $tZia = $this->login($this->contatto);
        $this->request('POST', '/api/emergenza/accetta', ['token' => $invito], $tZia);
        $this->assertStatus(200);
        $this->request('POST', "/api/emergenza/{$c['id']}/richiedi", [], $tZia);
        $annulla = $this->tokenDa($this->ultimaEmail());

        $this->request('POST', '/api/emergenza/annulla', ['token' => $annulla]);
        $this->assertStatus(200);
        $this->request('POST', '/api/emergenza/annulla', ['token' => $annulla]);
        $this->assertStatus(410);
        $this->scadiAttesa();
        self::assertSame(0, $this->servizio()->eseguiDovute());

        // Il ruolo d'emergenza non si assegna con un invito normale.
        $this->request('POST', "/api/tenants/{$this->tid()}/invitations", ['email' => 'x@example.com', 'roleId' => (string) $this->systemRole(Role::EMERGENZA)->getId()], $tOwner);
        $this->assertStatus(422);
    }

    /** Registro #16 (fase 2): una concessione che fallisce non blocca le altre. */
    public function testOneFailingGrantDoesNotBlockTheOthers(): void
    {
        $altra = $this->createTenant('Famiglia Bianchi');
        $ownerB = $this->createUser('ownerb@example.com');
        $this->addMember($ownerB, $altra, Role::OWNER);
        $zio = $this->createUser('zio@example.com');
        foreach ([[$this->tenant, $this->owner, $this->contatto], [$altra, $ownerB, $zio]] as [$t, $owner, $contatto]) {
            $this->mailer()->reset();
            $c = $this->request('POST', "/api/tenants/{$t->getId()}/emergenza/contatti", ['email' => $contatto->getEmail(), 'giorniAttesa' => 3], $this->login($owner));
            $this->assertStatus(201);
            $invito = $this->tokenDa($this->ultimaEmail());
            $token = $this->login($contatto);
            $this->request('POST', '/api/emergenza/accetta', ['token' => $invito], $token);
            $this->request('POST', "/api/emergenza/{$c['id']}/richiedi", [], $token);
            $this->assertStatus(200);
        }
        $this->scadiAttesa();
        $this->em()->getConnection()->executeStatement(sprintf(<<<'SQL'
            CREATE FUNCTION test_blocca_membership() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'concessione bloccata dal test'; END $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_blocca BEFORE INSERT ON membership FOR EACH ROW WHEN (NEW.tenant_id = '%s') EXECUTE FUNCTION test_blocca_membership();
            SQL, $this->tid()));

        self::assertSame(1, $this->servizio()->eseguiDovute(), 'solo quella riuscita');
        $db = $this->em()->getConnection();
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM membership WHERE user_id = ? AND tenant_id = ?', [(string) $zio->getId(), (string) $altra->getId()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM membership WHERE user_id = ?', [(string) $this->contatto->getId()]));
    }

    private function scadiAttesa(): void
    {
        $this->em()->createQuery('UPDATE '.ContattoEmergenza::class.' c SET c.eseguibileIl = :passato WHERE c.richiestaStato = :s')
            ->setParameter('passato', new \DateTimeImmutable('-1 minute'))->setParameter('s', ContattoEmergenza::IN_ATTESA)->execute();
        $this->em()->clear();
    }

    private function servizio(): EmergenzaService
    {
        return static::getContainer()->get(EmergenzaService::class);
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }
}
