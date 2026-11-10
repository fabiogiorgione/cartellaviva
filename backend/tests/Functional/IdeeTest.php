<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Portale\Message\RiepilogoIdeeMessage;
use App\Portale\MessageHandler\RiepilogoIdeeHandler;
use App\Tenancy\Entity\Role;
use Symfony\Component\Mime\Email;

/**
 * Portale delle idee: invio solo con account, idee private, gestione dal
 * portale per livello, duplicati, email senza contenuto, riepilogo giornaliero.
 */
final class IdeeTest extends ApiTestCase
{
    private const TESTO = 'Vorrei poter condividere una cartella intera con i nonni, in sola lettura.';

    private User $admin;
    private User $supporto;
    private User $lettore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@cartellaviva.local', superAdmin: true);
        $this->supporto = $this->createUser('supporto@cartellaviva.local');
        $this->lettore = $this->createUser('lettura@cartellaviva.local');
        $adminToken = $this->login($this->admin);
        $this->request('PUT', "/api/admin/utenti/{$this->supporto->getId()}/ruolo-piattaforma", ['ruolo' => 'supporto'], $adminToken);
        $this->request('PUT', "/api/admin/utenti/{$this->lettore->getId()}/ruolo-piattaforma", ['ruolo' => 'sola_lettura'], $adminToken);
    }

    public function testOnlyAccountsSendAndEachSeesOnlyTheirOwn(): void
    {
        $this->request('POST', '/api/idee', ['tipo' => 'funzionalita', 'testo' => self::TESTO]);
        $this->assertStatus(401);

        $mario = $this->createUser('mario@example.com');
        $famiglia = $this->createTenant('Famiglia Rossi');
        $this->addMember($mario, $famiglia, Role::OWNER);
        $token = $this->login($mario);

        $this->request('POST', '/api/idee', ['tipo' => 'funzionalita', 'testo' => 'troppo corto'], $token);
        $this->assertStatus(422);
        $this->request('POST', '/api/idee', ['tipo' => 'altro', 'testo' => self::TESTO], $token);
        $this->assertStatus(422);

        $idea = $this->request('POST', '/api/idee', [
            'tipo' => 'funzionalita', 'area' => 'documenti', 'testo' => '  '.self::TESTO.'  ',
            'tenantId' => (string) $famiglia->getId(), 'piattaforma' => 'android', 'versioneApp' => '1.4.0+12',
        ], $token);
        $this->assertStatus(201);
        self::assertSame([self::TESTO, 'nuova', true], [$idea['testo'], $idea['stato'], $idea['contattabile']]);

        // Del contesto si registra solo il tipo di spazio, e solo se l'utente ne fa parte.
        $estranea = $this->createTenant('Famiglia Bianchi');
        $this->request('POST', '/api/idee', ['tipo' => 'miglioramento', 'testo' => self::TESTO, 'tenantId' => (string) $estranea->getId(), 'piattaforma' => 'nokia'], $token);
        $portale = $this->request('GET', '/api/admin/idee', token: $this->login($this->lettore));
        self::assertSame([[null, null], ['famiglia', 'android']], array_map(static fn ($i) => [$i['tipoSpazio'], $i['piattaforma']], $portale));
        self::assertStringNotContainsString('Rossi', json_encode($portale));

        $giulia = $this->login($this->createUser('giulia@example.com'));
        self::assertSame([], $this->request('GET', '/api/idee', token: $giulia));
        self::assertCount(2, $this->request('GET', '/api/idee', token: $token));
        // Il portale resta riservato al team.
        $this->request('GET', '/api/admin/idee', token: $giulia);
        $this->assertStatus(403);
    }

    public function testDailyLimit(): void
    {
        $token = $this->login($this->createUser('mario@example.com'));
        for ($i = 0; $i < 5; ++$i) {
            $this->request('POST', '/api/idee', ['tipo' => 'miglioramento', 'testo' => self::TESTO." ($i)"], $token);
            $this->assertStatus(201);
        }
        $this->request('POST', '/api/idee', ['tipo' => 'miglioramento', 'testo' => self::TESTO], $token);
        $this->assertStatus(429);
    }

    public function testPortalLevelsContactAndNotificationsWithoutContent(): void
    {
        $mario = $this->login($this->createUser('mario@example.com'));
        $anna = $this->login($this->createUser('anna@example.com'));
        $id = $this->request('POST', '/api/idee', ['tipo' => 'funzionalita', 'testo' => self::TESTO], $mario)['id'];
        $this->request('POST', '/api/idee', ['tipo' => 'funzionalita', 'testo' => 'Anche io: condividere una cartella con i nonni!', 'contattabile' => false], $anna);

        $lettore = $this->login($this->lettore);
        $supporto = $this->login($this->supporto);
        // La sola lettura non vede i contatti e non modifica.
        self::assertNull($this->request('GET', "/api/admin/idee/$id", token: $lettore)['contatto']);
        $this->request('PATCH', "/api/admin/idee/$id", ['stato' => 'pianificata'], $lettore);
        $this->assertStatus(403);
        // Il supporto vede il contatto solo se concesso.
        $contatti = array_column($this->request('GET', '/api/admin/idee', token: $supporto), 'contatto');
        self::assertSame([null, 'mario@example.com'], array_map(static fn ($c) => $c['email'] ?? null, $contatti));

        $this->mailer()->reset();
        $this->request('PATCH', "/api/admin/idee/$id", ['stato' => 'letta', 'notaInterna' => 'valutare con il team'], $supporto);
        $this->assertStatus(200);
        self::assertCount(0, $this->mailer()->getEvents()->getMessages(), '"letta" e la nota interna non avvisano');

        $ok = $this->request('PATCH', "/api/admin/idee/$id", ['stato' => 'pianificata', 'rispostaPubblica' => 'Ottima idea, arriva in primavera.'], $supporto);
        self::assertSame(['pianificata', 'Ottima idea, arriva in primavera.'], [$ok['stato'], $ok['rispostaPubblica']]);
        $email = $this->ultimaEmail();
        self::assertSame('mario@example.com', $email->getTo()[0]->getAddress());
        self::assertStringNotContainsString('nonni', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('primavera', (string) $email->getHtmlBody());

        $mia = $this->request('GET', '/api/idee', token: $mario)[0];
        self::assertSame(['pianificata', 'Ottima idea, arriva in primavera.'], [$mia['stato'], $mia['rispostaPubblica']]);
        self::assertArrayNotHasKey('notaInterna', $mia);

        $this->request('PATCH', "/api/admin/idee/$id", ['stato' => 'boh'], $supporto);
        $this->assertStatus(422);
        $this->request('DELETE', "/api/admin/idee/$id", token: $supporto);
        $this->assertStatus(403);
        self::assertNotEmpty($this->auditRows('idea.aggiornata'));
    }

    public function testDuplicatesMergeAndShareStatus(): void
    {
        $mario = $this->login($this->createUser('mario@example.com'));
        $anna = $this->login($this->createUser('anna@example.com'));
        $luca = $this->login($this->createUser('luca@example.com'));
        $principale = $this->request('POST', '/api/idee', ['tipo' => 'funzionalita', 'testo' => self::TESTO], $mario)['id'];
        $dup = $this->request('POST', '/api/idee', ['tipo' => 'funzionalita', 'testo' => 'Condivisione di una cartella in sola lettura con i nonni.'], $anna)['id'];
        $dup2 = $this->request('POST', '/api/idee', ['tipo' => 'funzionalita', 'testo' => 'Cartelle condivise con i parenti, senza modifica.'], $luca)['id'];
        $supporto = $this->login($this->supporto);

        // dup2 -> dup, poi dup -> principale: dup2 segue dup sulla principale.
        $this->request('POST', "/api/admin/idee/$dup2/unisci", ['principaleId' => $dup], $supporto);
        $this->request('POST', "/api/admin/idee/$dup/unisci", ['principaleId' => $principale], $supporto);
        $this->assertStatus(200);
        $this->request('POST', "/api/admin/idee/$principale/unisci", ['principaleId' => $principale], $supporto);
        $this->assertStatus(409);

        $elenco = $this->request('GET', '/api/admin/idee', token: $supporto);
        self::assertSame([[$principale, 3]], array_map(static fn ($i) => [$i['id'], $i['richieste']], $elenco));
        self::assertCount(2, $this->request('GET', "/api/admin/idee/$principale", token: $supporto)['duplicati']);
        $this->request('PATCH', "/api/admin/idee/$dup", ['stato' => 'pianificata'], $supporto);
        $this->assertStatus(409);

        $this->mailer()->reset();
        $this->request('PATCH', "/api/admin/idee/$principale", ['stato' => 'realizzata'], $supporto);
        $destinatari = array_map(static fn (Email $e) => $e->getTo()[0]->getAddress(), $this->mailer()->getEvents()->getMessages());
        sort($destinatari);
        self::assertSame(['anna@example.com', 'luca@example.com', 'mario@example.com'], $destinatari);
        $diAnna = $this->request('GET', '/api/idee', token: $anna)[0];
        self::assertSame(['realizzata', true], [$diAnna['stato'], $diAnna['unita']]);
        self::assertStringNotContainsString('Vorrei poter', json_encode($diAnna), 'l\'autore di un duplicato non legge il testo della principale');

        // Separare; l'admin elimina la principale e i duplicati tornano autonomi.
        $this->request('POST', "/api/admin/idee/$dup2/unisci", ['principaleId' => null], $supporto);
        self::assertSame(2, $this->request('GET', "/api/admin/idee/$principale", token: $supporto)['richieste']);
        $this->request('DELETE', "/api/admin/idee/$principale", token: $this->login($this->admin));
        $this->assertStatus(204);
        self::assertCount(2, $this->request('GET', '/api/admin/idee', token: $supporto));
    }

    public function testDailySummaryOnlyCountsAndCsvExport(): void
    {
        $handler = static::getContainer()->get(RiepilogoIdeeHandler::class);
        $this->mailer()->reset();
        self::assertSame(['idee' => 0, 'email' => 0], $handler(new RiepilogoIdeeMessage()));

        $autore = $this->createUser('mario@example.com');
        $mario = $this->login($autore);
        $this->request('POST', '/api/idee', ['tipo' => 'funzionalita', 'testo' => self::TESTO], $mario);
        $this->request('POST', '/api/idee', ['tipo' => 'miglioramento', 'testo' => '=HYPERLINK("http://x") rendere più grande il pulsante'], $mario);
        self::assertContains('idee', array_column($this->request('GET', '/api/admin/salute', token: $this->login($this->lettore))['daFare'], 'tipo'));

        $this->mailer()->reset();
        self::assertSame(['idee' => 2, 'email' => 1], $handler(new RiepilogoIdeeMessage()));
        $email = $this->ultimaEmail();
        self::assertSame('admin@cartellaviva.local', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('2 nuove idee', (string) $email->getSubject());
        self::assertStringNotContainsString('nonni', (string) $email->getHtmlBody());
        self::assertSame(['idee' => 0, 'email' => 0], $handler(new RiepilogoIdeeMessage()), 'ogni idea entra in un solo riepilogo');

        $this->client->request('GET', '/api/admin/idee-export', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($this->supporto)]);
        $this->assertStatus(403);
        $this->client->request('GET', '/api/admin/idee-export', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($this->admin)]);
        $this->assertStatus(200);
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringNotContainsString('mario@example.com', $csv);

        // Account eliminato: l'idea resta, anonima.
        $this->em()->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$autore->getId()->toRfc4122()]);
        $idee = $this->request('GET', '/api/admin/idee', token: $this->login($this->supporto));
        self::assertSame([true, true], array_column($idee, 'anonima'));
    }
}
