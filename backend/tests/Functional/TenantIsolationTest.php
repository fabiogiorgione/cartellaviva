<?php

namespace App\Tests\Functional;

use App\Tenancy\Entity\Role;
use App\Tenancy\Enum\MembershipStatus;

/**
 * Regola: senza Membership attiva su un tenant non si vede nulla di quel
 * tenant, qualunque ruolo si abbia altrove.
 */
final class TenantIsolationTest extends ApiTestCase
{
    public function testOwnerOfAnotherTenantCannotSeeMembers(): void
    {
        $rossi = $this->createTenant('Rossi');
        $bianchi = $this->createTenant('Bianchi');
        $this->addMember($this->createUser('mario@example.com'), $rossi, Role::OWNER);
        $luca = $this->createUser('luca@example.com');
        $this->addMember($luca, $bianchi, Role::OWNER);

        $token = $this->login($luca);
        $this->request('GET', "/api/tenants/{$rossi->getId()}/members", token: $token);

        $this->assertStatus(403);
        $denied = $this->auditRows('permission.check', 'denied');
        self::assertCount(1, $denied);
        self::assertSame('membro:read', $denied[0]['permission']);
        self::assertSame((string) $rossi->getId(), $denied[0]['tenant_id']);
        self::assertStringContainsString('no_active_membership', $denied[0]['context']);
    }

    public function testTenantListContainsOnlyOwnTenants(): void
    {
        $rossi = $this->createTenant('Rossi');
        $bianchi = $this->createTenant('Bianchi');
        $luca = $this->createUser('luca@example.com');
        $this->addMember($luca, $bianchi, Role::VIEWER);
        $this->addMember($this->createUser('mario@example.com'), $rossi, Role::OWNER);

        $tenants = $this->request('GET', '/api/tenants', token: $this->login($luca));

        $this->assertStatus(200);
        self::assertSame([(string) $bianchi->getId()], array_column($tenants, 'id'));
        self::assertSame('viewer', $tenants[0]['myRole']);

        $this->request('GET', "/api/tenants/{$rossi->getId()}", token: $this->login($luca));
        $this->assertStatus(403);
    }

    public function testSuspendedMembershipGrantsNothing(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser('sospeso@example.com');
        $membership = $this->addMember($user, $tenant, Role::MEMBER);
        $membership->setStatus(MembershipStatus::SUSPENDED);
        $this->em()->flush();

        $this->request('GET', "/api/tenants/{$tenant->getId()}/members", token: $this->login($user));
        $this->assertStatus(403);

        $me = $this->request('GET', '/api/me', token: $this->login($user));
        self::assertSame([], $me['memberships']);
    }

    public function testRemovalTakesEffectWithoutWaitingForTokenExpiry(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->createUser('owner@example.com');
        $this->addMember($owner, $tenant, Role::OWNER);
        $member = $this->createUser('membro@example.com');
        $membership = $this->addMember($member, $tenant, Role::MEMBER);

        $memberToken = $this->login($member);
        $this->request('GET', "/api/tenants/{$tenant->getId()}/members", token: $memberToken);
        $this->assertStatus(200);

        $this->request('DELETE', "/api/tenants/{$tenant->getId()}/members/{$membership->getId()}", token: $this->login($owner));
        $this->assertStatus(204);

        // Stesso JWT, ancora valido: i permessi però si leggono dal DB.
        $this->request('GET', "/api/tenants/{$tenant->getId()}/members", token: $memberToken);
        $this->assertStatus(403);
    }

    public function testSuperAdminManagesMembersWithoutMembershipButGetsNoFamilyData(): void
    {
        $tenant = $this->createTenant();
        $this->addMember($this->createUser('owner@example.com'), $tenant, Role::OWNER);
        $admin = $this->createUser('admin@example.com', superAdmin: true);
        $token = $this->login($admin);

        $this->request('GET', "/api/tenants/{$tenant->getId()}/members", token: $token);
        $this->assertStatus(200);

        $security = static::getContainer()->get('security.authorization_checker');
        $tokenStorage = static::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($admin, 'api', $admin->getRoles()));
        self::assertTrue($security->isGranted('membro:manage_role', $tenant));
        self::assertFalse($security->isGranted('documento:read', $tenant), 'Il superadmin non accede ai documenti delle famiglie');
        self::assertFalse($security->isGranted('scadenza:read', $tenant));
    }
}
