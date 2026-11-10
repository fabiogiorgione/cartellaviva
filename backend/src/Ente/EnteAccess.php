<?php

namespace App\Ente;

use App\Account\Entity\User;
use App\Ente\Entity\EnteProfilo;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\NonTrovato;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accesso alle risorse di un ente.
 *
 * Oltre al permesso (sempre dal voter) c'è l'ambito per GRUPPO: un operatore
 * vede solo iscritti e richieste dei gruppi a cui è assegnato; chi ha
 * ente:tutti_i_gruppi (segreteria, proprietari) vede tutto.
 */
final class EnteAccess
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly PermissionResolver $permissions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Tenant ente con il permesso richiesto (404 se non è un ente). */
    public function requireEnte(string $tenantId, string $permission): Tenant
    {
        $tenant = $this->access->requireTenant($tenantId, $permission);
        if (TenantType::AZIENDA !== $tenant->getType()) {
            throw new NonTrovato('Ente non trovato.');
        }

        return $tenant;
    }

    public function profilo(Tenant $ente): EnteProfilo
    {
        return $this->em->getRepository(EnteProfilo::class)->findOneBy(['tenant' => $ente])
            ?? throw new NonTrovato('Profilo dell\'ente non trovato.');
    }

    /** Collegamenti e richieste partono solo da enti verificati. */
    public function assertVerificato(Tenant $ente): void
    {
        if (!$this->profilo($ente)->isVerificato()) {
            throw new Conflitto('L\'ente non è ancora verificato: potrai invitare le famiglie e inviare richieste dopo l\'approvazione.');
        }
    }

    public function currentUser(): User
    {
        return $this->access->currentUser();
    }

    public function require(string $permission, object $subject): void
    {
        $this->access->require($permission, $subject);
    }

    public function isGranted(string $permission, object $subject): bool
    {
        return $this->access->isGranted($permission, $subject);
    }

    public function vedeTutto(Tenant $ente): bool
    {
        $codes = $this->permissions->getPermissionCodes($this->currentUser(), $ente);

        return \in_array('ente:tutti_i_gruppi', $codes, true);
    }

    /**
     * Id dei gruppi visibili all'utente corrente; null = tutti.
     *
     * @return list<string>|null
     */
    public function gruppiVisibili(Tenant $ente): ?array
    {
        if ($this->vedeTutto($ente)) {
            return null;
        }
        $membership = $this->permissions->getActiveMembership($this->currentUser(), $ente);
        if (null === $membership) {
            return [];
        }
        $rows = $this->em->createQuery('SELECT g.id FROM '.Gruppo::class.' g JOIN g.operatori m WHERE g.tenant = :t AND m.id = :m')
            ->setParameter('t', $ente->getId(), 'uuid')
            ->setParameter('m', $membership->getId(), 'uuid')
            ->getScalarResult();

        return array_map(static fn (array $r) => (string) $r['id'], $rows);
    }

    public function puoVedereGruppo(Gruppo $gruppo): bool
    {
        $visibili = $this->gruppiVisibili($gruppo->getTenant());

        return null === $visibili || \in_array((string) $gruppo->getId(), $visibili, true);
    }

    public function puoVedereIscritto(Iscritto $iscritto): bool
    {
        $visibili = $this->gruppiVisibili($iscritto->getTenant());
        if (null === $visibili) {
            return true;
        }
        foreach ($iscritto->getGruppi() as $gruppo) {
            if (\in_array((string) $gruppo->getId(), $visibili, true)) {
                return true;
            }
        }

        return false;
    }

    public function assertGruppo(Gruppo $gruppo): void
    {
        if (!$this->puoVedereGruppo($gruppo)) {
            throw new NonTrovato('Gruppo non trovato.');
        }
    }

    public function assertIscritto(Iscritto $iscritto): void
    {
        if (!$this->puoVedereIscritto($iscritto)) {
            throw new NonTrovato('Iscritto non trovato.');
        }
    }
}
