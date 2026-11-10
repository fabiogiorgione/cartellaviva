<?php

namespace App\Tenancy;

use App\Account\EmailVerificata;
use App\Account\Entity\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\ErroreDominio;
use App\Shared\Errore\NonPiuValido;
use App\Shared\Mail\Posta;
use App\Shared\TokenCasuale;
use App\Tenancy\Entity\Invitation;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Repository\InvitationRepository;
use App\Tenancy\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Inviti: token casuale (256 bit) inviato per email, a DB solo il suo hash,
 * scadenza configurabile. L'invito porta alla registrazione (o all'accesso, se
 * l'email ha già un account) e crea la Membership con il ruolo indicato.
 */
final class InvitationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvitationRepository $invitations,
        private readonly MembershipRepository $memberships,
        private readonly RoleGrantPolicy $grantPolicy,
        private readonly Posta $posta,
        private readonly AuditLogger $audit,
        #[Autowire(param: 'app.frontend_url')]
        private readonly string $frontendUrl,
        #[Autowire(param: 'app.invitation_ttl_hours')]
        private readonly int $ttlHours,
    ) {
    }

    public function invite(User $actor, Tenant $tenant, string $email, Role $role): Invitation
    {
        $email = User::normalizeEmail($email);

        try {
            EmailVerificata::richiedi($actor);
            $this->grantPolicy->assertCanGrant($actor, $tenant, $role);

            $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null !== $existing && null !== $this->memberships->findOneByUserAndTenant($existing, $tenant)) {
                throw new Conflitto('Questa persona fa già parte del tenant.');
            }
        } catch (ErroreDominio $e) {
            $this->audit->log('invitation.created', AuditOutcome::DENIED, resourceType: 'invitation', tenantId: $tenant->getId(), context: ['email' => $email, 'role' => $role->getSlug(), 'reason' => $e->getMessage()]);
            throw $e;
        }

        // Un nuovo invito sostituisce quelli ancora pendenti per la stessa email.
        foreach ($this->invitations->findPendingFor($tenant, $email) as $previous) {
            $previous->revoke();
        }

        $plainToken = TokenCasuale::nuovo();
        $invitation = new Invitation($tenant, $email, $role, Invitation::hashToken($plainToken), new \DateTimeImmutable(sprintf('+%d hours', $this->ttlHours)), $actor);
        $this->em->persist($invitation);
        $this->em->flush();

        $this->posta->invia($email, sprintf('%s ti ha invitato su CartellaViva', $actor->getFullName()), 'emails/invitation.html.twig', [
                'inviter' => $actor->getFullName(),
                'tenantName' => $tenant->getName(),
                'roleName' => $role->getName(),
                'acceptUrl' => rtrim($this->frontendUrl, '/').'/auth/invite?token='.$plainToken,
                'expiresAt' => $invitation->getExpiresAt(),
            ]);

        $this->audit->log('invitation.created', AuditOutcome::SUCCESS, resourceType: 'invitation', resourceId: (string) $invitation->getId(), tenantId: $tenant->getId(), context: ['email' => $email, 'role' => $role->getSlug()]);

        return $invitation;
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->revoke();
        $this->em->flush();
        $this->audit->log('invitation.revoked', AuditOutcome::SUCCESS, resourceType: 'invitation', resourceId: (string) $invitation->getId(), tenantId: $invitation->getTenant()->getId(), context: ['email' => $invitation->getEmail()]);
    }

    public function assertPending(Invitation $invitation, ?User $user = null): void
    {
        if (!$invitation->isPending()) {
            $this->audit->log('invitation.accepted', AuditOutcome::DENIED, resourceType: 'invitation', resourceId: (string) $invitation->getId(), tenantId: $invitation->getTenant()->getId(), context: ['email' => $invitation->getEmail(), 'reason' => $invitation->getStatus()], actor: $user);
            throw new NonPiuValido('Invito scaduto, revocato o già utilizzato.');
        }
    }

    /** Crea la Membership per l'utente (la cui email deve coincidere con quella invitata). */
    public function accept(Invitation $invitation, User $user): Membership
    {
        $context = ['email' => $invitation->getEmail(), 'role' => $invitation->getRole()->getSlug()];

        $this->assertPending($invitation, $user);
        if ($user->getEmail() !== $invitation->getEmail()) {
            $this->audit->log('invitation.accepted', AuditOutcome::DENIED, resourceType: 'invitation', resourceId: (string) $invitation->getId(), tenantId: $invitation->getTenant()->getId(), context: $context + ['reason' => 'email_mismatch'], actor: $user);
            throw new Conflitto('L\'invito è intestato a un altro indirizzo email.');
        }
        if (null !== $this->memberships->findOneByUserAndTenant($user, $invitation->getTenant())) {
            throw new Conflitto('Fai già parte di questo tenant.');
        }

        $membership = new Membership($user, $invitation->getTenant(), $invitation->getRole(), $invitation->getInvitedBy());
        $invitation->accept($user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->audit->log('invitation.accepted', AuditOutcome::SUCCESS, resourceType: 'membership', resourceId: (string) $membership->getId(), tenantId: $invitation->getTenant()->getId(), context: $context, actor: $user);

        return $membership;
    }
}
