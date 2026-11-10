<?php

namespace App\Tests\Functional;

final class AuthenticationTest extends ApiTestCase
{
    public function testLoginReturnsAccessAndRefreshTokenAndIsAudited(): void
    {
        $user = $this->createUser('login@example.com');

        $response = $this->request('POST', '/api/auth/login', ['email' => 'LOGIN@example.com ', 'password' => self::PASSWORD]);

        $this->assertStatus(200);
        self::assertNotEmpty($response['token']);
        self::assertNotEmpty($response['refresh_token']);
        self::assertCount(1, $this->auditRows('auth.login', 'success'));
    }

    public function testWrongPasswordIsRejectedAndAudited(): void
    {
        $this->createUser('wrong@example.com');

        $this->request('POST', '/api/auth/login', ['email' => 'wrong@example.com', 'password' => 'sbagliata']);

        $this->assertStatus(401);
        $rows = $this->auditRows('auth.login', 'failure');
        self::assertCount(1, $rows);
        self::assertSame('wrong@example.com', $rows[0]['actor_email']);
    }

    public function testRefreshTokenIsSingleUse(): void
    {
        $this->createUser('refresh@example.com');
        $login = $this->request('POST', '/api/auth/login', ['email' => 'refresh@example.com', 'password' => self::PASSWORD]);

        $first = $this->request('POST', '/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertStatus(200);
        self::assertNotSame($login['refresh_token'], $first['refresh_token'], 'Il refresh deve ruotare il token');

        // Il vecchio refresh token non è più utilizzabile (rotazione).
        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertStatus(401);
    }

    public function testLogoutInvalidatesRefreshToken(): void
    {
        $user = $this->createUser('logout@example.com');
        $login = $this->request('POST', '/api/auth/login', ['email' => 'logout@example.com', 'password' => self::PASSWORD]);

        $this->request('POST', '/api/auth/logout', ['refresh_token' => $login['refresh_token']], $login['token']);
        self::assertLessThan(300, $this->client->getResponse()->getStatusCode());

        $this->request('POST', '/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $this->assertStatus(401);
    }

    public function testDeactivatedUserIsBlockedEvenWithValidJwt(): void
    {
        $user = $this->createUser('inactive@example.com');
        $token = $this->login($user);
        $this->request('GET', '/api/me', token: $token);
        $this->assertStatus(200);

        $this->fresh($user)->setActive(false);
        $this->em()->flush();

        $this->request('GET', '/api/me', token: $token);
        $this->assertStatus(401);
    }

    public function testRegistrationCreatesUserAndPrivateTenantAsOwner(): void
    {
        $response = $this->request('POST', '/api/auth/register', [
            'email' => 'nuova@example.com',
            'password' => 'una-password-lunga',
            'firstName' => 'Anna',
            'lastName' => 'Verdi',
        ]);
        $this->assertStatus(201);

        $login = $this->request('POST', '/api/auth/login', ['email' => 'nuova@example.com', 'password' => 'una-password-lunga']);
        $me = $this->request('GET', '/api/me', token: $login['token']);

        self::assertCount(1, $me['memberships']);
        self::assertSame($response['tenantId'], $me['memberships'][0]['tenant']['id']);
        self::assertSame('Famiglia Verdi', $me['memberships'][0]['tenant']['name']);
        self::assertSame('owner', $me['memberships'][0]['role']['slug']);
        self::assertFalse($me['isSuperAdmin']);
    }

    public function testRegistrationRejectsDuplicateEmailAndShortPassword(): void
    {
        $this->createUser('esistente@example.com');

        $this->request('POST', '/api/auth/register', ['email' => 'esistente@example.com', 'password' => 'una-password-lunga', 'firstName' => 'A', 'lastName' => 'B']);
        $this->assertStatus(409);

        $this->request('POST', '/api/auth/register', ['email' => 'altra@example.com', 'password' => 'corta', 'firstName' => 'A', 'lastName' => 'B']);
        $this->assertStatus(422);
    }

    public function testProtectedEndpointsRequireAuthentication(): void
    {
        $this->request('GET', '/api/me');
        $this->assertStatus(401);
        $this->request('GET', '/api/tenants');
        $this->assertStatus(401);
    }
}
