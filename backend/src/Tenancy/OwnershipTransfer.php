<?php

namespace App\Tenancy;

use App\Account\Entity\User;
use App\Documenti\CondivisioneService;
use App\Import\Entity\ImportSource;
use App\Shared\Entity\Contract\OwnedResourceInterface;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Quando una persona esce da un tenant (rimossa o di sua iniziativa) i dati di
 * cui era proprietaria passano a un proprietario del tenant:
 * - tutte le risorse con owner (documenti, persone, …): cambia solo l'owner;
 * - le sorgenti di import che aveva collegato: erano legate al SUO account
 *   Google, quindi si scollegano (token cancellati) e vanno ricollegate.
 *
 * Senza questo passaggio i dati resterebbero di un utente che non fa più parte
 * del tenant, e la cancellazione del suo account sarebbe bloccata dai vincoli.
 */
final class OwnershipTransfer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipRepository $memberships,
    ) {
    }

    /**
     * Chi eredita: chi esegue la rimozione, se è un proprietario attivo diverso da
     * chi esce; altrimenti il proprietario attivo più anziano.
     */
    public function successorFor(Tenant $tenant, User $leaving, ?User $actor): ?User
    {
        $owners = array_values(array_filter(
            $this->memberships->findActiveOwnerUsers($tenant),
            static fn (User $u) => !$u->getId()->equals($leaving->getId()),
        ));
        foreach ($owners as $owner) {
            if (null !== $actor && $owner->getId()->equals($actor->getId())) {
                return $owner;
            }
        }

        return $owners[0] ?? null;
    }

    /**
     * Esegue il passaggio (aggiornamenti in blocco, dentro la transazione del chiamante).
     *
     * @return array<string, int> risorse trasferite per tipo (per l'audit)
     */
    public function transferAll(Tenant $tenant, User $from, User $to): array
    {
        $counts = [];
        // I link esterni creati da chi esce smettono di funzionare (poi passano al successore, revocati).
        $revocate = CondivisioneService::revocaDi($this->em, $tenant, $from);
        if ($revocate > 0) {
            $counts['condivisioniRevocate'] = $revocate;
        }
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || !$metadata->getReflectionClass()->implementsInterface(OwnedResourceInterface::class)) {
                continue;
            }
            $updated = (int) $this->em->createQueryBuilder()
                ->update($metadata->getName(), 'r')
                ->set('r.owner', ':to')
                ->where('r.tenant = :tenant')
                ->andWhere('r.owner = :from')
                ->setParameter('to', $to->getId(), 'uuid')
                ->setParameter('tenant', $tenant->getId(), 'uuid')
                ->setParameter('from', $from->getId(), 'uuid')
                ->getQuery()
                ->execute();
            if ($updated > 0) {
                $counts[lcfirst($metadata->getReflectionClass()->getShortName())] = $updated;
            }
        }

        /** @var list<ImportSource> $sources */
        $sources = $this->em->getRepository(ImportSource::class)->findBy(['tenant' => $tenant, 'creatoDa' => $from]);
        foreach ($sources as $source) {
            $source->handOverTo($to);
        }
        if ([] !== $sources) {
            $counts['importSource'] = \count($sources);
        }

        return $counts;
    }
}
