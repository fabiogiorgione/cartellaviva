<?php

namespace App\Portale;

use App\Account\Entity\RefreshToken;
use App\Account\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use Doctrine\ORM\EntityManagerInterface;

/** Azioni del team del portale sugli account: blocco e ruolo di piattaforma. */
final class UtentiPortale
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlatformAccess $platform,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Blocca o sblocca l'account; il blocco chiude anche tutte le sessioni. */
    public function impostaAttivo(User $actor, User $user, bool $attivo, ?string $motivo): void
    {
        if ($user->getId()->equals($actor->getId())) {
            throw new Conflitto('Non puoi bloccare il tuo account.');
        }
        if (null !== $user->getPlatformRole() && !$this->platform->has($actor, PlatformAccess::ADMIN)) {
            throw new Conflitto('Gli account del team del portale li gestisce il superadmin.');
        }
        $user->setActive($attivo);
        if (!$attivo) {
            $this->em->createQuery('DELETE FROM '.RefreshToken::class.' r WHERE r.username = :e')->setParameter('e', $user->getEmail())->execute();
        }
        $this->em->flush();
        $this->audit->log($attivo ? 'portale.utente_sbloccato' : 'portale.utente_bloccato', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['motivo' => $motivo], actor: $actor);
    }

    /** Ruolo di piattaforma: supporto, sola_lettura o nessuno. Il superadmin non si assegna da qui. */
    public function assegnaRuolo(User $actor, User $user, ?string $ruolo): void
    {
        if ($user->isSuperAdmin()) {
            throw new Conflitto('Il ruolo di superadmin si gestisce dalla console del server.');
        }
        try {
            $user->setPlatformRole($ruolo);
        } catch (\InvalidArgumentException) {
            throw new DatiNonValidi('ruolo: supporto, sola_lettura o null.');
        }
        $this->em->flush();
        $this->audit->log('portale.ruolo_assegnato', AuditOutcome::SUCCESS, resourceType: 'user', resourceId: (string) $user->getId(), context: ['ruolo' => $user->getPlatformRole()], actor: $actor);
    }
}
