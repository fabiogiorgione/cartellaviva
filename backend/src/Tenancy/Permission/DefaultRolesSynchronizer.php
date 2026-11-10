<?php

namespace App\Tenancy\Permission;

use App\Tenancy\Entity\PermissionGroup;
use App\Tenancy\Entity\Role;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\PermissionGroupRepository;
use App\Tenancy\Repository\PermissionRepository;
use App\Tenancy\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Crea/aggiorna i PermissionGroup e i Role di sistema da config/packages/rbac.yaml
 * (idempotente, con i permessi associati).
 * I ruoli personalizzati dei tenant non vengono toccati.
 */
final class DefaultRolesSynchronizer
{
    /**
     * @param array<string, array{name: string, description?: string, permissions: list<string>}>                                                          $groupDefinitions
     * @param array<string, array{name: string, description?: string, tenant_type?: ?string, permissions?: list<string>, permission_groups?: list<string>}> $roleDefinitions
     */
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionRepository $permissions,
        private readonly PermissionGroupRepository $groups,
        private readonly RoleRepository $roles,
        private readonly EntityManagerInterface $em,
        #[Autowire(param: 'app.rbac.permission_groups')]
        private readonly array $groupDefinitions,
        #[Autowire(param: 'app.rbac.roles')]
        private readonly array $roleDefinitions,
    ) {
    }

    /** @return array{groups: list<string>, roles: list<string>} */
    public function synchronize(): array
    {
        $groupsBySlug = [];
        foreach ($this->groupDefinitions as $slug => $definition) {
            $group = $this->groups->findSystemGroup($slug) ?? new PermissionGroup($slug, $definition['name']);
            $group->describe($definition['name'], $definition['description'] ?? null);
            $group->replacePermissions($this->resolvePermissions($definition['permissions'], "gruppo $slug"));
            $this->em->persist($group);
            $groupsBySlug[$slug] = $group;
        }

        foreach ($this->roleDefinitions as $slug => $definition) {
            $tenantType = isset($definition['tenant_type']) ? TenantType::from($definition['tenant_type']) : null;
            $role = $this->roles->findSystemRole($slug) ?? new Role($slug, $definition['name'], null, $tenantType);
            $role->describe($definition['name'], $definition['description'] ?? null);
            $role->setTenantType($tenantType);
            $role->replacePermissions($this->resolvePermissions($definition['permissions'] ?? [], "ruolo $slug"));
            $role->replacePermissionGroups(array_map(
                static fn (string $g) => $groupsBySlug[$g] ?? throw new \InvalidArgumentException(sprintf('Ruolo "%s": gruppo "%s" non definito.', $slug, $g)),
                $definition['permission_groups'] ?? [],
            ));
            $this->em->persist($role);
        }

        $this->em->flush();

        return ['groups' => array_keys($this->groupDefinitions), 'roles' => array_keys($this->roleDefinitions)];
    }

    /**
     * @param list<string> $codes
     *
     * @return list<\App\Tenancy\Entity\Permission>
     */
    private function resolvePermissions(array $codes, string $owner): array
    {
        if (\in_array('*', $codes, true)) {
            $codes = $this->catalog->codes();
        }
        foreach ($codes as $code) {
            if (!$this->catalog->has($code)) {
                throw new \InvalidArgumentException(sprintf('%s: permesso "%s" non presente nel catalogo.', ucfirst($owner), $code));
            }
        }
        $found = $this->permissions->findActiveByCodes($codes);
        if (\count($found) !== \count(array_unique($codes))) {
            throw new \RuntimeException('Catalogo non sincronizzato a DB: eseguire prima app:permissions:sync.');
        }

        return $found;
    }
}
