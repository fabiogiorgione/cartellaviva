<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\ApiResource\RoleResource;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Permission\PermissionCatalog;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\Repository\PermissionRepository;
use App\Tenancy\Repository\RoleRepository;
use App\Tenancy\RoleGrantPolicy;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Ruoli personalizzati del tenant. I ruoli di sistema non si modificano via API.
 *
 * @implements ProcessorInterface<RoleResource, RoleResource|null>
 */
final class RoleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly RoleRepository $roles,
        private readonly PermissionRepository $permissions,
        private readonly MembershipRepository $memberships,
        private readonly PermissionCatalog $catalog,
        private readonly RoleGrantPolicy $grantPolicy,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?RoleResource
    {
        $actor = $this->access->currentUser();
        $tenantId = (string) $uriVariables['tenantId'];
        $tenant = $this->access->requireTenant($tenantId, 'ruolo:manage');

        if ($operation instanceof Post) {
            \assert($data instanceof RoleResource);
            if (!$tenant->isModalitaAvanzata()) {
                throw new ConflictHttpException('I ruoli personalizzati richiedono la modalità avanzata: attivala nelle impostazioni della famiglia.');
            }
            $codes = $this->validatedCodes($data->permissions);
            $this->grantPolicy->assertCanGrantPermissions($actor, $tenant, $codes);

            $role = new Role($this->uniqueSlug($tenant, $data->name), trim($data->name), $tenant);
            $role->describe(trim($data->name), $data->description);
            $role->replacePermissions($this->permissions->findActiveByCodes($codes));
            $this->em->persist($role);
            $this->em->flush();
            $this->audit->log('role.created', AuditOutcome::SUCCESS, resourceType: 'role', resourceId: (string) $role->getId(), tenantId: $tenant->getId(), context: ['permissions' => $codes]);

            return RoleResource::fromEntity($role, $tenantId);
        }

        $role = $this->roles->findAssignableById($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Ruolo non trovato.');
        if ($role->isSystem()) {
            throw new AccessDeniedHttpException('I ruoli di sistema non sono modificabili: crea un ruolo personalizzato.');
        }

        if ($operation instanceof DeleteOperationInterface) {
            if ($this->memberships->countUsingRole($role) > 0) {
                throw new ConflictHttpException('Ruolo assegnato ad almeno un membro: riassegna prima i membri.');
            }
            $this->audit->log('role.deleted', AuditOutcome::SUCCESS, resourceType: 'role', resourceId: (string) $role->getId(), tenantId: $tenant->getId(), context: ['name' => $role->getName()]);
            $this->em->remove($role);
            $this->em->flush();

            return null;
        }

        \assert($data instanceof RoleResource);
        $codes = $this->validatedCodes($data->permissions);
        $this->grantPolicy->assertCanGrantPermissions($actor, $tenant, $codes);
        $role->describe(trim($data->name), $data->description);
        $role->replacePermissions($this->permissions->findActiveByCodes($codes));
        $role->replacePermissionGroups([]);
        $this->em->flush();
        $this->audit->log('role.updated', AuditOutcome::SUCCESS, resourceType: 'role', resourceId: (string) $role->getId(), tenantId: $tenant->getId(), context: ['permissions' => $codes]);

        return RoleResource::fromEntity($role, $tenantId);
    }

    /**
     * @param list<mixed> $codes
     *
     * @return list<string>
     */
    private function validatedCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_map('strval', $codes)));
        $unknown = array_filter($codes, fn (string $c) => !$this->catalog->has($c));
        if ([] !== $unknown) {
            throw new UnprocessableEntityHttpException('Permessi sconosciuti: '.implode(', ', $unknown));
        }

        return $codes;
    }

    private function uniqueSlug(Tenant $tenant, string $name): string
    {
        $base = strtolower((string) (new AsciiSlugger())->slug($name)) ?: 'ruolo';
        $base = substr($base, 0, 70);
        $slug = $base;
        for ($i = 2; null !== $this->roles->findOneBy(['tenant' => $tenant, 'slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
