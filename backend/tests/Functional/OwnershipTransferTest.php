<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Import\Entity\ImportSource;
use App\Import\Enum\ImportProvider;
use App\Import\Enum\ImportStrategia;
use App\Import\ImportTokenVault;
use App\Import\OAuthTokens;
use App\Schedule;
use App\Shared\Message\PuliziaPeriodicaMessage;
use App\Shared\MessageHandler\PuliziaPeriodicaHandler;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;

/** Fase 0: chi esce dalla famiglia non si porta via (né blocca) i dati; pulizia pianificata. */
final class OwnershipTransferTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('Famiglia Rossi');
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
    }

    public function testRemovedMemberDocumentsPassToTheOwnerWhoRemovedThem(): void
    {
        $member = $this->createUser('membro@example.com');
        $membership = $this->addMember($member, $this->tenant, Role::MEMBER);
        $doc = $this->upload($this->tid(), $this->login($member), $this->fixture('ocr_fattura.png'), 'bolletta.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);
        $this->assertStatus(201);

        $this->request('DELETE', "/api/tenants/{$this->tid()}/members/{$membership->getId()}", token: $this->login($this->owner));

        $this->assertStatus(204);
        self::assertSame($this->owner->getId()->toRfc4122(), $this->ownerOf($doc['id']));
        $audit = $this->auditRows('membership.ownership_transferred', 'success');
        self::assertCount(1, $audit);
        self::assertSame(['documento' => 1], json_decode($audit[0]['context'], true)['risorse']);
    }

    public function testMemberWhoLeavesHandsDocumentsToAnOwner(): void
    {
        $member = $this->createUser('membro@example.com');
        $membership = $this->addMember($member, $this->tenant, Role::MEMBER);
        $token = $this->login($member);
        $doc = $this->upload($this->tid(), $token, $this->fixture('ocr_fattura.png'), 'x.png', ['categoriaId' => (string) $this->categoria('casa')->getId()]);

        $this->request('DELETE', "/api/tenants/{$this->tid()}/members/{$membership->getId()}", token: $token);

        $this->assertStatus(204);
        self::assertSame($this->owner->getId()->toRfc4122(), $this->ownerOf($doc['id']));
    }

    public function testLeavingOwnerDisconnectsTheGoogleDriveSourceHeLinked(): void
    {
        $second = $this->createUser('secondo@example.com');
        $secondMembership = $this->addMember($second, $this->tenant, Role::OWNER);
        $source = new ImportSource($this->fresh($this->tenant), $this->fresh($second), ImportProvider::GOOGLE_DRIVE, 'radice', ImportStrategia::CARTELLE);
        $this->em()->persist($source);
        static::getContainer()->get(ImportTokenVault::class)->store($source, new OAuthTokens('a', 'r', new \DateTimeImmutable('+1 hour')));
        $source->markConnected('secondo@gmail.com');
        $this->em()->flush();

        $this->request('DELETE', "/api/tenants/{$this->tid()}/members/{$secondMembership->getId()}", token: $this->login($second));

        $this->assertStatus(204);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT creato_da_user_id, stato, oauth_refresh_token, encrypted_dek FROM import_source');
        self::assertSame($this->owner->getId()->toRfc4122(), $row['creato_da_user_id']);
        self::assertSame('non_configurato', $row['stato']);
        self::assertNull($row['oauth_refresh_token'], 'I token del suo account Google non restano nella famiglia');
        self::assertNull($row['encrypted_dek']);
    }

    public function testHourlyCleanupIsScheduledAndRemovesExpiredOAuthStates(): void
    {
        $messages = (new Schedule(static::getContainer()->get('cache.app')))->getSchedule()->getRecurringMessages();
        self::assertCount(4, $messages, 'pulizia oraria + promemoria scadenze + automazioni enti + riepilogo idee');

        $source = new ImportSource($this->fresh($this->tenant), $this->fresh($this->owner), ImportProvider::GOOGLE_DRIVE, 'radice', ImportStrategia::CARTELLE);
        $source->beginOAuth(str_repeat('a', 64), $this->fresh($this->owner), new \DateTimeImmutable('-1 minute'), 'web');
        $this->em()->persist($source);
        $this->em()->flush();

        $result = static::getContainer()->get(PuliziaPeriodicaHandler::class)(new PuliziaPeriodicaMessage());

        self::assertSame(1, $result['stati_oauth']);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT oauth_state_hash FROM import_source'));
    }

    private function tid(): string
    {
        return (string) $this->tenant->getId();
    }

    private function ownerOf(string $documentoId): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT owner_user_id FROM documento WHERE id = ?', [$documentoId]);
    }
}
