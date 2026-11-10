<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use Symfony\Component\Mime\Email;

final class InvitationTest extends ApiTestCase
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

    public function testInvitationFlowForNewUser(): void
    {
        $this->request('POST', "/api/tenants/{$this->tenant->getId()}/invitations", [
            'email' => 'Nonno@Example.com',
            'roleId' => (string) $this->systemRole(Role::VIEWER)->getId(),
        ], $this->login($this->owner));
        $this->assertStatus(201);

        self::assertEmailCount(1);
        $token = $this->extractToken(self::getMailerMessage());

        $preview = $this->request('GET', '/api/invitations/preview?token='.$token);
        $this->assertStatus(200);
        self::assertSame('nonno@example.com', $preview['email']);
        self::assertSame('Famiglia Rossi', $preview['tenantName']);
        self::assertFalse($preview['accountExists']);

        $this->request('POST', '/api/invitations/accept', ['token' => $token, 'firstName' => 'Giuseppe', 'lastName' => 'Rossi', 'password' => 'password-del-nonno']);
        $this->assertStatus(201);

        $login = $this->request('POST', '/api/auth/login', ['email' => 'nonno@example.com', 'password' => 'password-del-nonno']);
        $me = $this->request('GET', '/api/me', token: $login['token']);
        self::assertSame('viewer', $me['memberships'][0]['role']['slug']);

        // Token monouso.
        $this->request('POST', '/api/invitations/accept', ['token' => $token, 'firstName' => 'X', 'lastName' => 'Y', 'password' => 'password-del-nonno']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [409, 410]);
    }

    public function testExistingUserMustLoginAndEmailMustMatch(): void
    {
        $existing = $this->createUser('zia@example.com');
        $this->request('POST', "/api/tenants/{$this->tenant->getId()}/invitations", [
            'email' => 'zia@example.com',
            'roleId' => (string) $this->systemRole(Role::MEMBER)->getId(),
        ], $this->login($this->owner));
        $token = $this->extractToken(self::getMailerMessage());

        $this->request('POST', '/api/invitations/accept', ['token' => $token, 'firstName' => 'A', 'lastName' => 'B', 'password' => 'password-lunga-1']);
        $this->assertStatus(409);

        $intruder = $this->createUser('intruso@example.com');
        $this->request('POST', '/api/invitations/accept', ['token' => $token], $this->login($intruder));
        $this->assertStatus(409);
        self::assertStringContainsString('email_mismatch', $this->auditRows('invitation.accepted', 'denied')[0]['context']);

        $this->request('POST', '/api/invitations/accept', ['token' => $token], $this->login($existing));
        $this->assertStatus(201);
    }

    public function testMemberCannotInviteAndNonOwnerCannotInviteOwner(): void
    {
        $member = $this->createUser('giulia@example.com');
        $this->addMember($member, $this->tenant, Role::MEMBER);

        $this->request('POST', "/api/tenants/{$this->tenant->getId()}/invitations", [
            'email' => 'x@example.com',
            'roleId' => (string) $this->systemRole(Role::VIEWER)->getId(),
        ], $this->login($member));
        $this->assertStatus(403);
        self::assertEmailCount(0);
    }

    public function testRevokedOrExpiredInvitationCannotBeAccepted(): void
    {
        $ownerToken = $this->login($this->owner);
        $created = $this->request('POST', "/api/tenants/{$this->tenant->getId()}/invitations", [
            'email' => 'revocato@example.com',
            'roleId' => (string) $this->systemRole(Role::VIEWER)->getId(),
        ], $ownerToken);
        $token = $this->extractToken(self::getMailerMessage());

        $this->request('DELETE', "/api/tenants/{$this->tenant->getId()}/invitations/{$created['id']}", token: $ownerToken);
        $this->assertStatus(204);

        $this->request('POST', '/api/invitations/accept', ['token' => $token, 'firstName' => 'A', 'lastName' => 'B', 'password' => 'password-lunga-1']);
        $this->assertStatus(410);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'revocato@example.com']), 'Nessun account creato per un invito revocato');
    }

    public function testInvitingAnExistingMemberIsRejected(): void
    {
        $this->request('POST', "/api/tenants/{$this->tenant->getId()}/invitations", [
            'email' => 'owner@example.com',
            'roleId' => (string) $this->systemRole(Role::VIEWER)->getId(),
        ], $this->login($this->owner));
        $this->assertStatus(409);
    }

    private function extractToken(?\Symfony\Component\Mime\RawMessage $message): string
    {
        self::assertInstanceOf(Email::class, $message);
        self::assertMatchesRegularExpression('#/auth/invite\?token=([A-Za-z0-9_-]+)#', (string) $message->getHtmlBody());
        preg_match('#/auth/invite\?token=([A-Za-z0-9_-]+)#', (string) $message->getHtmlBody(), $m);

        return $m[1];
    }
}
