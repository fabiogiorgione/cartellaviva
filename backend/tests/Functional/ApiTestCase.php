<?php

namespace App\Tests\Functional;

use App\Account\Entity\User;
use App\Account\TwoFactor\TotpService;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class ApiTestCase extends WebTestCase
{
    protected const PASSWORD = 'Password-di-test-1';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $email, bool $superAdmin = false, bool $verificata = true): User
    {
        $user = new User($email, 'Nome', 'Cognome');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $user->setSuperAdmin($superAdmin);
        if ($verificata) {
            $user->segnaEmailVerificata();
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function createTenant(string $name = 'Famiglia Test', TenantType $type = TenantType::PRIVATO): Tenant
    {
        $tenant = new Tenant($name, $type);
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /**
     * Dopo ogni richiesta il services_resetter svuota l'EntityManager:
     * le entità create prima vanno ricaricate prima di modificarle.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    protected function fresh(object $entity): object
    {
        return $this->em()->find($entity::class, $entity->getId());
    }

    protected function addMember(User $user, Tenant $tenant, string $roleSlug): Membership
    {
        $membership = new Membership($this->fresh($user), $this->fresh($tenant), $this->systemRole($roleSlug));
        $this->em()->persist($membership);
        $this->em()->flush();

        return $membership;
    }

    protected function systemRole(string $slug): Role
    {
        return static::getContainer()->get(RoleRepository::class)->getSystemRole($slug);
    }

    protected function login(User $user): string
    {
        $response = $this->request('POST', '/api/auth/login', ['email' => $user->getEmail(), 'password' => self::PASSWORD]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $response['token'];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>|null
     */
    protected function request(string $method, string $uri, ?array $body = null, ?string $token = null, string $contentType = 'application/json'): ?array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $contentType];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true);
    }

    /**
     * Caricamento multipart di un documento.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<mixed>|null
     */
    protected function upload(string $tenantId, string $token, string $contents, string $fileName, array $fields): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'fv-upload-');
        file_put_contents($path, $contents);
        $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, $fileName, null, null, true);
        $this->client->request('POST', "/api/tenants/$tenantId/documenti", $fields, ['file' => $file], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'multipart/form-data',
        ]);
        @unlink($path);
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? null : json_decode($content, true);
    }

    protected function categoria(string $slug): \App\Documenti\Entity\Categoria
    {
        return static::getContainer()->get(\App\Documenti\Repository\CategoriaRepository::class)->findSystemBySlug($slug);
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__).'/Fixtures/'.$name);
    }

    protected function patch(string $uri, array $body, string $token): ?array
    {
        return $this->request('PATCH', $uri, $body, $token, 'application/merge-patch+json');
    }

    protected function assertStatus(int $expected): void
    {
        self::assertSame($expected, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    /** @return list<array<string, mixed>> */
    protected function auditRows(string $action, ?string $outcome = null): array
    {
        $sql = 'SELECT * FROM audit_log WHERE action = :action'.(null !== $outcome ? ' AND outcome = :outcome' : '').' ORDER BY id';
        $params = ['action' => $action] + (null !== $outcome ? ['outcome' => $outcome] : []);

        return $this->em()->getConnection()->fetchAllAssociative($sql, $params);
    }

    /** Email inviate nella richiesta corrente (il registro si azzera a ogni richiesta del test). */
    protected function mailer(): MessageLoggerListener
    {
        return static::getContainer()->get('mailer.message_logger_listener');
    }

    protected function ultimaEmail(): Email
    {
        $messages = $this->mailer()->getEvents()->getMessages();
        $last = end($messages);
        self::assertInstanceOf(Email::class, $last, 'nessuna email inviata');

        return $last;
    }

    /** Il token di un link nell'email; con $percorso, solo quello del link /$percorso?token=... */
    protected function tokenDa(Email $email, string $percorso = ''): string
    {
        $regex = '' === $percorso ? '/token=([A-Za-z0-9_-]+)/' : '#/'.preg_quote($percorso, '#').'\?token=([A-Za-z0-9_-]+)#';
        self::assertMatchesRegularExpression($regex, (string) $email->getHtmlBody());
        preg_match($regex, (string) $email->getHtmlBody(), $m);

        return $m[1];
    }

    protected function totp(): TotpService
    {
        return static::getContainer()->get(TotpService::class);
    }
}
