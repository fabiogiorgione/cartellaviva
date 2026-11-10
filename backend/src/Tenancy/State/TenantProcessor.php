<?php

namespace App\Tenancy\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\ApiResource\TenantResource;
use App\Tenancy\Entity\Invitation;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantAccess;
use App\Tenancy\TenantProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @implements ProcessorInterface<TenantResource, TenantResource>
 */
final class TenantProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantProvisioner $provisioner,
        private readonly PermissionResolver $resolver,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TenantResource
    {
        \assert($data instanceof TenantResource);
        $user = $this->access->currentUser();

        if ($operation instanceof Post) {
            // I tenant di tipo "azienda" (enti) per ora li crea solo l'amministratore della piattaforma.
            if (TenantType::AZIENDA === $data->type && !$user->isSuperAdmin()) {
                $this->audit->log('tenant.created', AuditOutcome::DENIED, resourceType: 'tenant', context: ['type' => $data->type->value, 'reason' => 'superadmin_required']);
                throw new AccessDeniedHttpException('Solo l\'amministratore della piattaforma può creare tenant di tipo azienda.');
            }
            $membership = $this->provisioner->createWithOwner($user, trim($data->name), $data->type);

            return TenantResource::fromEntity($membership->getTenant(), $membership);
        }

        $tenant = $this->access->requireTenant((string) $uriVariables['id'], 'tenant:manage');
        $oldName = $tenant->getName();
        $wasAdvanced = $tenant->isModalitaAvanzata();
        $tenant->rename(trim($data->name));
        if ($data->modalitaAvanzata !== $wasAdvanced) {
            if (TenantType::AZIENDA === $tenant->getType()) {
                throw new ConflictHttpException('Gli enti usano sempre i ruoli personalizzati.');
            }
            if (!$data->modalitaAvanzata && $this->customRolesInUse($tenant) > 0) {
                throw new ConflictHttpException('Alcuni membri o inviti usano un ruolo personalizzato: assegna loro Proprietario, Membro o Lettore prima di spegnere la modalità avanzata.');
            }
            $tenant->setModalitaAvanzata($data->modalitaAvanzata);
        }
        $this->em->flush();
        $this->audit->log('tenant.updated', AuditOutcome::SUCCESS, resourceType: 'tenant', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), context: ['from' => $oldName, 'to' => $tenant->getName(), 'modalita_avanzata' => $tenant->isModalitaAvanzata()]);

        return TenantResource::fromEntity($tenant, $this->resolver->getActiveMembership($user, $tenant));
    }

    /** Membership (non rimosse) e inviti in attesa con un ruolo personalizzato del tenant. */
    private function customRolesInUse(Tenant $tenant): int
    {
        $memberships = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.Membership::class.' m JOIN m.role r WHERE m.tenant = :t AND r.tenant = :t')
            ->setParameter('t', $tenant->getId(), 'uuid')->getSingleScalarResult();
        $invitations = (int) $this->em->createQuery('SELECT COUNT(i.id) FROM '.Invitation::class.' i JOIN i.role r WHERE i.tenant = :t AND r.tenant = :t AND i.acceptedAt IS NULL AND i.revokedAt IS NULL AND i.expiresAt > :now')
            ->setParameter('t', $tenant->getId(), 'uuid')->setParameter('now', new \DateTimeImmutable())->getSingleScalarResult();

        return $memberships + $invitations;
    }
}
