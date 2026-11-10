<?php

namespace App\Tests\Functional;

use App\Tenancy\Entity\Role;
use App\Tenancy\Enum\TenantType;

/** Fase 8: primi passi calcolati dai dati, riquadro nascondibile, introduzione vista una volta. */
final class AccoglienzaTest extends ApiTestCase
{
    public function testFamilyStepsFollowRealDataAndCanBeHidden(): void
    {
        $tenant = $this->createTenant('Famiglia Rossi');
        $owner = $this->createUser('owner@example.com');
        $this->addMember($owner, $tenant, Role::OWNER);
        $token = $this->login($owner);
        $url = "/api/tenants/{$tenant->getId()}/primi-passi";

        $passi = fn () => array_column($this->request('GET', $url, token: $token)['passi'], 'fatto', 'id');
        $r = $this->request('GET', $url, token: $token);
        self::assertSame(['famiglia', false], [$r['tipo'], $r['nascosto']]);
        self::assertSame(['profilo', 'persone', 'documento', 'scadenza', 'famigliare', 'sicurezza', 'emergenza'], array_column($r['passi'], 'id'));
        self::assertFalse($passi()['documento']);

        $this->upload((string) $tenant->getId(), $token, $this->fixture('ocr_fattura.png'), 'bolletta.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->request('POST', "/api/tenants/{$tenant->getId()}/scadenze", ['titolo' => 'Revisione auto', 'data' => '2027-05-01', 'ricorrenza' => 'nessuna'], $token);
        $this->addMember($this->createUser('giulia@example.com'), $tenant, Role::MEMBER);
        $fatti = $passi();
        self::assertSame([true, true, true], [$fatti['documento'], $fatti['scadenza'], $fatti['famigliare']]);

        $this->request('POST', $url, ['nascosto' => true], $token);
        $this->assertStatus(204);
        self::assertTrue($this->request('GET', $url, token: $token)['nascosto']);
        $this->request('POST', $url, ['nascosto' => false], $token);
        self::assertFalse($this->request('GET', $url, token: $token)['nascosto']);

        // Un estraneo non vede i primi passi di un'altra famiglia.
        $this->request('GET', $url, token: $this->login($this->createUser('estraneo@example.com')));
        $this->assertStatus(403);
    }

    public function testEnteStepsAndIntroSeenOnce(): void
    {
        $ente = $this->createTenant('Scuola Verdi', TenantType::AZIENDA);
        $owner = $this->createUser('segreteria@example.com');
        $this->addMember($owner, $ente, Role::OWNER);
        $token = $this->login($owner);

        $r = $this->request('GET', "/api/tenants/{$ente->getId()}/primi-passi", token: $token);
        $this->assertStatus(200);
        self::assertSame('ente', $r['tipo']);
        self::assertSame(['ente_verificato', 'ente_gruppi', 'ente_iscritti', 'ente_inviti', 'ente_richiesta', 'ente_operatori'], array_column($r['passi'], 'id'));
        self::assertNotContains(true, array_column($r['passi'], 'fatto'));

        self::assertFalse($this->request('GET', '/api/me', token: $token)['introVista']);
        $this->request('POST', '/api/me/intro-vista', token: $token);
        $this->assertStatus(204);
        self::assertTrue($this->request('GET', '/api/me', token: $token)['introVista']);
    }
}
