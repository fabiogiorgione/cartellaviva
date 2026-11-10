<?php

namespace App\Tenancy\Permission;

use App\Tenancy\Entity\Permission;
use App\Tenancy\Repository\PermissionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Allinea la tabella "permission" al catalogo compilato.
 *
 * Le voci sparite dal catalogo vengono marcate obsolete, non cancellate: il voter
 * le ignora e un eventuale riferimento storico resta leggibile.
 */
final class PermissionSynchronizer
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionRepository $permissions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{created: list<string>, updated: list<string>, obsolete: list<string>}
     */
    public function synchronize(): array
    {
        $existing = $this->permissions->findAllIndexedByCode();
        $result = ['created' => [], 'updated' => [], 'obsolete' => []];

        foreach ($this->catalog->all() as $code => $definition) {
            $permission = $existing[$code] ?? null;
            if (null === $permission) {
                $permission = new Permission($code);
                $this->em->persist($permission);
                $result['created'][] = $code;
            } else {
                $result['updated'][] = $code;
            }
            $permission->syncFromCatalog($definition['label'], $definition['sensitive']);
            unset($existing[$code]);
        }

        foreach ($existing as $code => $permission) {
            if (!$permission->isObsolete()) {
                $permission->markObsolete();
                $result['obsolete'][] = $code;
            }
        }

        $this->em->flush();

        return $result;
    }
}
