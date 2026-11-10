<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;

final class MemberManagementTest extends ApiTestCase
{
    private Tenant $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->owner = $this->createUser('owner@example.com');
        $this->addMember($this->owner, $this->tenant, Role::OWNER);
    }

    public function testOwnerChangesRoleOfMember(): void
    {
        $membership = $this->addMember($this->createUser('giulia@example.com'), $this->tenant, Role::MEMBER);

        $response = $this->patch($this->memberUri($membership->getId()), ['roleId' => (string) $this->systemRole(Role::VIEWER)->getId()], $this->login($this->owner));

        $this->assertStatus(200);
        self::assertSame('viewer', $response['roleSlug']);
        self::assertCount(1, $this->auditRows('membership.role_changed', 'success'));
    }

    public function testViewerCannotChangeRolesAndDenialIsAudited(): void
    {
        $viewer = $this->createUser('nonno@example.com');
        $this->addMember($viewer, $this->tenant, Role::VIEWER);
        $target = $this->addMember($this->createUser('giulia@example.com'), $this->tenant, Role::MEMBER);

        $this->patch($this->memberUri($target->getId()), ['roleId' => (string) $this->systemRole(Role::OWNER)->getId()], $this->login($viewer));

        $this->assertStatus(403);
        $denied = $this->auditRows('permission.check', 'denied');
        self::assertSame('membro:manage_role', end($denied)['permission']);
    }

    public function testMemberCannotRemoveOthersButCanLeave(): void
    {
        $member = $this->createUser('giulia@example.com');
        $own = $this->addMember($member, $this->tenant, Role::MEMBER);
        $other = $this->addMember($this->createUser('altro@example.com'), $this->tenant, Role::MEMBER);
        $token = $this->login($member);

        $this->request('DELETE', $this->memberUri($other->getId()), token: $token);
        $this->assertStatus(403);

        $this->request('DELETE', $this->memberUri($own->getId()), token: $token);
        $this->assertStatus(204);
        self::assertCount(1, $this->auditRows('membership.left', 'success'));
    }

    public function testLastOwnerCannotBeDemotedRemovedOrSuspended(): void
    {
        $ownerMembership = $this->em()->getRepository(\App\Tenancy\Entity\Membership::class)->findOneBy(['user' => $this->owner]);
        $token = $this->login($this->owner);

        $errore = $this->patch($this->memberUri($ownerMembership->getId()), ['roleId' => (string) $this->systemRole(Role::MEMBER)->getId()], $token);
        $this->assertStatus(409);
        // Errore di dominio da un'operazione API Platform: stesso formato e messaggio di un'eccezione HTTP.
        self::assertSame([409, 'Il tenant deve avere almeno un proprietario attivo: nomina prima un altro proprietario.'], [$errore['status'], $errore['detail']]);

        $this->request('DELETE', $this->memberUri($ownerMembership->getId()), token: $token);
        $this->assertStatus(409);

        self::assertCount(1, $this->auditRows('membership.role_changed', 'denied'));
        self::assertCount(1, $this->auditRows('membership.left', 'denied'));
    }

    public function testOwnerCanStepDownOnceThereIsAnotherOwner(): void
    {
        $second = $this->createUser('secondo@example.com');
        $secondMembership = $this->addMember($second, $this->tenant, Role::MEMBER);
        $ownerMembership = $this->em()->getRepository(\App\Tenancy\Entity\Membership::class)->findOneBy(['user' => $this->owner]);
        $token = $this->login($this->owner);

        $this->patch($this->memberUri($secondMembership->getId()), ['roleId' => (string) $this->systemRole(Role::OWNER)->getId()], $token);
        $this->assertStatus(200);

        $this->patch($this->memberUri($ownerMembership->getId()), ['roleId' => (string) $this->systemRole(Role::MEMBER)->getId()], $token);
        $this->assertStatus(200);

        // Ora l'ex owner è "member": non può più toccare l'altro owner.
        $this->request('DELETE', $this->memberUri($secondMembership->getId()), token: $token);
        $this->assertStatus(403);
    }

    public function testCustomRoleCannotEscalateToOwnerOrGrantMissingPermissions(): void
    {
        $ownerToken = $this->login($this->owner);
        $this->patch("/api/tenants/{$this->tenant->getId()}", ['modalitaAvanzata' => true], $ownerToken);
        $this->assertStatus(200);
        $manager = $this->request('POST', "/api/tenants/{$this->tenant->getId()}/roles", [
            'name' => 'Gestore membri',
            'permissions' => ['membro:read', 'membro:manage_role', 'documento:read'],
        ], $ownerToken);
        $this->assertStatus(201);

        $delegate = $this->createUser('delegato@example.com');
        $delegateMembership = $this->addMember($delegate, $this->tenant, Role::VIEWER);
        $this->patch($this->memberUri($delegateMembership->getId()), ['roleId' => $manager['id']], $ownerToken);
        $this->assertStatus(200);

        $target = $this->addMember($this->createUser('target@example.com'), $this->tenant, Role::VIEWER);
        $delegateToken = $this->login($delegate);

        // Non può assegnare "owner"...
        $this->patch($this->memberUri($target->getId()), ['roleId' => (string) $this->systemRole(Role::OWNER)->getId()], $delegateToken);
        $this->assertStatus(403);
        // ...né "member", che include permessi che il delegato non ha (documento:create, ...).
        $this->patch($this->memberUri($target->getId()), ['roleId' => (string) $this->systemRole(Role::MEMBER)->getId()], $delegateToken);
        $this->assertStatus(403);
        // Il suo stesso ruolo sì: i permessi sono un sottoinsieme dei suoi.
        $this->patch($this->memberUri($target->getId()), ['roleId' => $manager['id']], $delegateToken);
        $this->assertStatus(200);
    }

    public function testSystemRolesAreReadOnlyAndListedFirst(): void
    {
        $token = $this->login($this->owner);
        $roles = $this->request('GET', "/api/tenants/{$this->tenant->getId()}/roles", token: $token);

        $this->assertStatus(200);
        self::assertSame(['owner', 'member', 'viewer'], array_column($roles, 'slug'), 'staff è solo per tenant azienda');

        $this->patch("/api/tenants/{$this->tenant->getId()}/roles/{$roles[0]['id']}", ['name' => 'Capo'], $token);
        $this->assertStatus(403);
    }

    private function memberUri(mixed $membershipId): string
    {
        return "/api/tenants/{$this->tenant->getId()}/members/{$membershipId}";
    }
}
