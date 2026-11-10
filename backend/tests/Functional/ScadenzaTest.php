<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Enum\ScadenzaRicorrenza;
use App\Scadenze\Message\PromemoriaScadenzeMessage;
use App\Scadenze\MessageHandler\PromemoriaScadenzeHandler;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\Uid\Uuid;

final class ScadenzaTest extends ApiTestCase
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

    public function testMemberCreatesOwnDeadlineLinkedToDocumentAndPerson(): void
    {
        $token = $this->login($this->member);
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'libretto.png', ['categoriaId' => (string) $this->categoria('veicoli')->getId()]);
        $leo = $this->persona('Leo');

        $s = $this->request('POST', $this->base(), [
            'titolo' => 'Revisione auto', 'data' => $this->giorni(29), 'ricorrenza' => 'nessuna', 'promemoriaGiorni' => 7,
            'documentoId' => $doc['id'], 'membroId' => (string) $leo->getId(), 'categoriaId' => (string) $this->categoria('veicoli')->getId(),
        ], $token);

        $this->assertStatus(201);
        self::assertSame('aperta', $s['stato']);
        self::assertSame(29, $s['giorniMancanti']);
        self::assertSame('Revisione auto', $s['titolo']);
        self::assertSame($doc['id'], $s['documento']['id']);
        self::assertSame('Leo', $s['membro']['nome']);
        self::assertSame('Veicoli', $s['categoria']['nome']);
        self::assertSame($this->member->getId()->toRfc4122(), $s['ownerId']);

        $list = $this->request('GET', $this->base().'?documentoId='.$doc['id'], token: $token);
        self::assertSame([$s['id']], array_column($list, 'id'));
    }

    public function testOwnScopeAndViewerAndOtherTenant(): void
    {
        $ownerS = $this->request('POST', $this->base(), ['titolo' => 'TARI', 'data' => $this->giorni(10)], $this->login($this->owner));
        $memberToken = $this->login($this->member);

        $this->patch($this->base()."/{$ownerS['id']}", ['titolo' => 'Hack'], $memberToken);
        $this->assertStatus(403);
        $this->request('POST', $this->base()."/{$ownerS['id']}/fatta", token: $memberToken);
        $this->assertStatus(403);

        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $viewerToken = $this->login($viewer);
        $this->request('GET', $this->base(), token: $viewerToken);
        $this->assertStatus(200);
        $this->request('POST', $this->base(), ['titolo' => 'X', 'data' => $this->giorni(1)], $viewerToken);
        $this->assertStatus(403);

        $altro = $this->createUser('luca@example.com');
        $this->addMember($altro, $this->createTenant('Famiglia Bianchi'), Role::OWNER);
        $this->request('GET', $this->base(), token: $this->login($altro));
        $this->assertStatus(403);
    }

    public function testLinksMustBelongToTheSameFamily(): void
    {
        $altroTenant = $this->createTenant('Famiglia Bianchi');
        $altroOwner = $this->createUser('luca@example.com');
        $this->addMember($altroOwner, $altroTenant, Role::OWNER);
        $docAltrui = $this->upload((string) $altroTenant->getId(), $this->login($altroOwner), $this->fixture('ocr_fattura.png'), 'x.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);

        $this->request('POST', $this->base(), ['titolo' => 'X', 'data' => $this->giorni(5), 'documentoId' => $docAltrui['id']], $this->login($this->owner));

        $this->assertStatus(422);
    }

    public function testCompletingARecurringDeadlineCreatesTheNextOneAtMonthEnd(): void
    {
        $token = $this->login($this->owner);
        $s = $this->request('POST', $this->base(), ['titolo' => 'Bolletta luce', 'data' => '2027-01-31', 'ricorrenza' => 'mensile', 'promemoriaGiorni' => 3], $token);

        $res = $this->request('POST', $this->base()."/{$s['id']}/fatta", token: $token);

        $this->assertStatus(200);
        self::assertSame('fatta', $res['scadenza']['stato']);
        self::assertSame('2027-02-28', $res['prossima']['data'], 'fine mese: 31 gennaio -> 28 febbraio');
        self::assertSame('mensile', $res['prossima']['ricorrenza']);
        self::assertSame(3, $res['prossima']['promemoriaGiorni']);

        $this->request('POST', $this->base()."/{$s['id']}/fatta", token: $token);
        $this->assertStatus(409);
    }

    public function testRecurrenceArithmetic(): void
    {
        $d = static fn (string $s) => new \DateTimeImmutable($s);
        self::assertSame('2028-02-28', ScadenzaRicorrenza::ANNUALE->next($d('2027-02-28'))?->format('Y-m-d'));
        self::assertSame('2029-02-28', ScadenzaRicorrenza::ANNUALE->next($d('2028-02-29'))?->format('Y-m-d'));
        self::assertSame('2027-01-15', ScadenzaRicorrenza::MENSILE->next($d('2026-12-15'))?->format('Y-m-d'));
        self::assertNull(ScadenzaRicorrenza::NESSUNA->next($d('2026-12-15')));
    }

    public function testDeletingTheDocumentKeepsTheDeadline(): void
    {
        $token = $this->login($this->owner);
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'ci.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $s = $this->request('POST', $this->base(), ['titolo' => 'Rinnovo', 'data' => $this->giorni(40), 'documentoId' => $doc['id']], $token);

        $this->request('DELETE', "/api/tenants/{$this->tid()}/documenti/{$doc['id']}", token: $token);
        $this->assertStatus(204);

        $after = $this->request('GET', $this->base()."/{$s['id']}", token: $token);
        self::assertNull($after['documento']);
        self::assertTrue($after['documentoRimosso']);
    }

    public function testDailyReminderOneEmailPerUserWithoutTitlesAndNeverTwice(): void
    {
        $token = $this->login($this->owner);
        $this->request('POST', $this->base(), ['titolo' => 'Visita oncologica', 'data' => $this->giorni(3), 'promemoriaGiorni' => 7], $token);
        $this->request('POST', $this->base(), ['titolo' => 'Bolletta', 'data' => $this->giorni(1), 'promemoriaGiorni' => 2], $token);
        $this->request('POST', $this->base(), ['titolo' => 'Lontana', 'data' => $this->giorni(60), 'promemoriaGiorni' => 7], $token);
        $this->request('POST', $this->base(), ['titolo' => 'Senza promemoria', 'data' => $this->giorni(1)], $token);

        $result = $this->handler()(new PromemoriaScadenzeMessage());

        self::assertSame(['scadenze' => 2, 'email' => 1], $result);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', 'owner@example.com');
        self::assertEmailHtmlBodyNotContains($email, 'oncologica');
        self::assertEmailHtmlBodyContains($email, 'Famiglia Rossi');

        self::assertSame(['scadenze' => 0, 'email' => 0], $this->handler()(new PromemoriaScadenzeMessage()));
    }

    public function testChangingTheDateReschedulesTheReminder(): void
    {
        $token = $this->login($this->owner);
        $s = $this->request('POST', $this->base(), ['titolo' => 'Bolletta', 'data' => $this->giorni(1), 'promemoriaGiorni' => 2], $token);
        $this->handler()(new PromemoriaScadenzeMessage());

        $this->patch($this->base()."/{$s['id']}", ['data' => $this->giorni(2)], $token);
        $this->assertStatus(200);

        self::assertSame(1, $this->handler()(new PromemoriaScadenzeMessage())['scadenze']);
    }

    public function testDeadlinesFollowOwnershipWhenMemberLeaves(): void
    {
        $memberToken = $this->login($this->member);
        $s = $this->request('POST', $this->base(), ['titolo' => 'Mia', 'data' => $this->giorni(5)], $memberToken);
        $membership = $this->em()->getConnection()->fetchOne('SELECT id FROM membership WHERE user_id = ?', [$this->member->getId()->toRfc4122()]);

        $this->request('DELETE', "/api/tenants/{$this->tid()}/members/$membership", token: $this->login($this->owner));

        $this->em()->clear();
        self::assertSame($this->owner->getId()->toRfc4122(), $this->em()->find(Scadenza::class, Uuid::fromString($s['id']))->getOwner()->getId()->toRfc4122());
    }

    private function persona(string $nome): MembroFamiglia
    {
        $body = $this->request('POST', "/api/tenants/{$this->tid()}/membri_famiglia", ['nome' => $nome, 'cognome' => '', 'relazione' => 'figlio'], $this->login($this->owner));
        if (!isset($body['id'])) {
            self::fail('Creazione persona non riuscita: '.json_encode($body));
        }

        return $this->em()->find(MembroFamiglia::class, Uuid::fromString($body['id']));
    }

    private function giorni(int $n): string
    {
        return (new \DateTimeImmutable('today'))->modify("+$n days")->format('Y-m-d');
    }

    private function base(): string
    {
        return "/api/tenants/{$this->tid()}/scadenze";
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }

    private function handler(): PromemoriaScadenzeHandler
    {
        return static::getContainer()->get(PromemoriaScadenzeHandler::class);
    }
}
