<?php

namespace App\Shared\Doctrine;

use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Shared\Entity\Contract\TenantScopedInterface;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Rete di sicurezza a livello di persistenza per le risorse applicative:
 * - al primo salvataggio il proprietario deve avere una Membership attiva sul tenant;
 * - il tenant di un record non si cambia mai dopo la creazione.
 * Le colonne NOT NULL del TenantOwnedTrait impediscono già record orfani.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class TenantOwnershipGuard
{
    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof OwnedResourceInterface) {
            return;
        }

        $membership = $this->memberships->findOneByUserAndTenant($entity->getOwner(), $entity->getTenant());
        if (null === $membership || !$membership->isActive()) {
            throw new \DomainException(sprintf('%s: il proprietario deve essere un membro attivo del tenant.', $entity::class));
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($args->getObject() instanceof TenantScopedInterface && $args->hasChangedField('tenant')) {
            throw new \DomainException(sprintf('%s: il tenant di un record non può essere cambiato.', $args->getObject()::class));
        }
    }
}
