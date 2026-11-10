<?php

namespace App\Tests\Unit;

use App\Account\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Permission\PermissionCatalog;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantPermissionVoter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Logica pura del voter (senza DB): scope :own / :all e soggetto obbligatorio.
 */
final class TenantPermissionVoterTest extends TestCase
{
    private Tenant $tenant;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        $this->tenant = new Tenant('T', TenantType::PRIVATO);
        $this->alice = new User('alice@example.com', 'Alice', 'A');
        $this->bob = new User('bob@example.com', 'Bob', 'B');
    }

    public function testOwnScopeGrantsOnlyOnOwnResources(): void
    {
        $voter = $this->voter(['documento:read', 'documento:write:own']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, 'documento:write', $this->resource($this->alice)));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, 'documento:write', $this->resource($this->bob)));
        // Su un Tenant (nessun proprietario) lo scope :own non basta.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, 'documento:write', $this->tenant));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, 'documento:read', $this->resource($this->bob)));
    }

    public function testAllScopeGrantsOnEveryResourceOfTheTenant(): void
    {
        $voter = $this->voter(['documento:write:all']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, 'documento:write', $this->resource($this->bob)));
    }

    public function testNoSubjectMeansDenied(): void
    {
        $voter = $this->voter(['documento:read']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, 'documento:read', null));
    }

    public function testUnknownPermissionIsDenied(): void
    {
        $voter = $this->voter(['documento:read']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, 'documento:fly', $this->tenant));
    }

    public function testNonPermissionAttributesAreIgnored(): void
    {
        $voter = $this->voter([]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, 'ROLE_USER', $this->tenant));
    }

    /** @param list<string> $codes */
    private function voter(array $codes): TenantPermissionVoter
    {
        $catalog = new PermissionCatalog([
            'documento' => ['label' => 'Documenti', 'sensitive' => true, 'actions' => ['read' => '', 'write:own' => '', 'write:all' => '']],
        ]);

        $membership = $this->createStub(Membership::class);
        $resolver = $this->createStub(PermissionResolver::class);
        $resolver->method('getActiveMembership')->willReturn($membership);
        $resolver->method('getPermissionCodes')->willReturn($codes);

        return new TenantPermissionVoter($catalog, $resolver, $this->createStub(AuditLogger::class), new NullLogger());
    }

    private function vote(TenantPermissionVoter $voter, string $attribute, mixed $subject): int
    {
        return $voter->vote(new UsernamePasswordToken($this->alice, 'api', ['ROLE_USER']), $subject, [$attribute]);
    }

    private function resource(User $owner): OwnedResourceInterface
    {
        return new class($this->tenant, $owner) implements OwnedResourceInterface {
            public function __construct(private Tenant $tenant, private User $owner)
            {
            }

            public function getTenant(): Tenant
            {
                return $this->tenant;
            }

            public function getOwner(): User
            {
                return $this->owner;
            }
        };
    }
}
