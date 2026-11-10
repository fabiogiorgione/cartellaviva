<?php

namespace App\Tests\Functional;

use App\Tenancy\Entity\Role;

final class AuditLogTest extends ApiTestCase
{
    public function testOwnerReadsTenantLogButMemberCannot(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->createUser('owner@example.com');
        $this->addMember($owner, $tenant, Role::OWNER);
        $member = $this->createUser('membro@example.com');
        $this->addMember($member, $tenant, Role::MEMBER);

        // Genera un accesso negato registrato sul tenant.
        $memberToken = $this->login($member);
        $this->request('GET', "/api/tenants/{$tenant->getId()}/audit_logs", token: $memberToken);
        $this->assertStatus(403);

        $logs = $this->request('GET', "/api/tenants/{$tenant->getId()}/audit_logs", token: $this->login($owner));
        $this->assertStatus(200);
        $denied = array_values(array_filter($logs, static fn (array $l) => 'denied' === $l['outcome']));
        self::assertSame('membro@example.com', $denied[0]['actorEmail']);
        self::assertSame('audit:read', $denied[0]['permission']);
    }

    public function testAdminLogIsReservedToSuperAdmin(): void
    {
        $user = $this->createUser('utente@example.com');
        $this->request('GET', '/api/admin/audit_logs', token: $this->login($user));
        $this->assertStatus(403);

        $admin = $this->createUser('admin@example.com', superAdmin: true);
        $this->request('GET', '/api/admin/audit_logs', token: $this->login($admin));
        $this->assertStatus(200);
    }

    public function testAuditLogIsAppendOnlyAtDatabaseLevel(): void
    {
        $this->createUser('x@example.com');
        $this->request('POST', '/api/auth/login', ['email' => 'x@example.com', 'password' => 'sbagliata']);

        $this->expectExceptionMessageMatches('/append-only/');
        $this->em()->getConnection()->executeStatement("UPDATE audit_log SET outcome = 'success'");
    }
}
