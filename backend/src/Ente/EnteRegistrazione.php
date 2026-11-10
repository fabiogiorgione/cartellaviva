<?php

namespace App\Ente;

use App\Account\Entity\User;
use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\EnteProfilo;
use App\Ente\Enum\EnteStato;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Errore\Conflitto;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\TroppiTentativi;
use App\Shared\Mail\Posta;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\MembershipStatus;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\MembershipRepository;
use App\Tenancy\TenantProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Registrazione di un ente e conferma dell'email istituzionale, decisione del
 * portale. Chi registra l'ente ne diventa proprietario.
 */
final class EnteRegistrazione
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantProvisioner $provisioner,
        private readonly EnteDatiValidator $validator,
        private readonly MembershipRepository $memberships,
        private readonly Posta $posta,
        private readonly AuditLogger $audit,
        #[Autowire('%app.frontend_url%')]
        private readonly string $frontendUrl,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function registra(User $user, array $data): EnteProfilo
    {
        $dati = $this->validator->validate($data);
        $esistente = $this->em->getRepository(EnteProfilo::class)->findOneBy(['codiceFiscale' => $dati['codiceFiscale']]);
        if (null !== $esistente && EnteStato::RESPINTO !== $esistente->getStato()) {
            // Non si dice chi l'ha registrato: si invita a farsi aggiungere come operatore.
            throw new Conflitto('Un ente con questo codice fiscale è già registrato: chiedi alla segreteria di aggiungerti come operatore.');
        }

        $membership = $this->provisioner->createWithOwner($user, $dati['denominazione'], TenantType::AZIENDA);
        $tenant = $membership->getTenant();
        $profilo = new EnteProfilo($tenant, $dati['tipo'], $dati['codiceFiscale'], $dati['emailIstituzionale']);
        $profilo->aggiornaDati($dati['codiceFiscale'], $dati['codiceMeccanografico'], $dati['numeroRunts'], $dati['emailIstituzionale'], $dati['sedeLegale']);
        $this->em->persist($profilo);
        $this->em->persist(new AnnoEnte($tenant, self::annoCorrente(new \DateTimeImmutable())));
        $this->em->flush();
        $this->audit->log('ente.registrato', AuditOutcome::SUCCESS, resourceType: 'ente', resourceId: (string) $tenant->getId(), tenantId: $tenant->getId(), context: ['tipo' => $dati['tipo']->value], actor: $user);
        $this->inviaCodice($profilo);

        return $profilo;
    }

    /** @param array<string, mixed> $data */
    public function aggiorna(EnteProfilo $profilo, array $data, User $user): void
    {
        $dati = $this->validator->validate($data + ['tipo' => $profilo->getTipo()->value]);
        if ($dati['tipo'] !== $profilo->getTipo()) {
            throw new DatiNonValidi('tipo: non si può cambiare dopo la registrazione.');
        }
        $profilo->getTenant()->rename($dati['denominazione']);
        $daConfermare = $profilo->aggiornaDati($dati['codiceFiscale'], $dati['codiceMeccanografico'], $dati['numeroRunts'], $dati['emailIstituzionale'], $dati['sedeLegale']);
        $this->em->flush();
        $this->audit->log('ente.aggiornato', AuditOutcome::SUCCESS, resourceType: 'ente', resourceId: (string) $profilo->getTenant()->getId(), tenantId: $profilo->getTenant()->getId(), context: ['nuova_conferma' => $daConfermare], actor: $user);
        if ($daConfermare) {
            $this->inviaCodice($profilo);
        }
    }

    public function inviaCodice(EnteProfilo $profilo): void
    {
        if (EnteStato::EMAIL_DA_CONFERMARE !== $profilo->getStato()) {
            throw new Conflitto('L\'email istituzionale è già confermata.');
        }
        $codice = str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
        $profilo->nuovoCodice($this->hash($profilo, $codice));
        $this->em->flush();
        $this->posta->invia($profilo->getEmailIstituzionale(), 'Codice di conferma per CartellaViva', 'emails/ente_codice.html.twig', ['ente' => $profilo->getTenant()->getName(), 'codice' => $codice]);
    }

    public function conferma(EnteProfilo $profilo, string $codice, User $user): void
    {
        $esito = $profilo->verificaCodice($this->hash($profilo, trim($codice)));
        $this->em->flush();
        $this->audit->log('ente.email_confermata', 'ok' === $esito ? AuditOutcome::SUCCESS : AuditOutcome::FAILURE, resourceType: 'ente', resourceId: (string) $profilo->getTenant()->getId(), tenantId: $profilo->getTenant()->getId(), context: ['esito' => $esito], actor: $user);
        match ($esito) {
            'ok' => null,
            'errato' => throw new DatiNonValidi('Codice non corretto.'),
            'bloccato' => throw new TroppiTentativi('Troppi tentativi: richiedi un nuovo codice.'),
            default => throw new DatiNonValidi('Codice scaduto: richiedine uno nuovo.'),
        };
    }

    /**
     * Decisione del portale (solo superadmin). Approvare richiede l'email
     * confermata e il controllo sul registro pubblico; la telefonata è facoltativa.
     *
     * @param array<string, mixed> $controlli
     */
    public function decidi(EnteProfilo $profilo, string $azione, ?string $nota, array $controlli, User $admin): void
    {
        $controlli = ['registro' => true === ($controlli['registro'] ?? false), 'telefonata' => true === ($controlli['telefonata'] ?? false)];
        $nota = null !== $nota && '' !== trim($nota) ? mb_substr(trim($nota), 0, 1000) : null;
        $stato = match ($azione) {
            'approva' => EnteStato::VERIFICATO,
            'integrazioni' => EnteStato::INTEGRAZIONI,
            'respingi' => EnteStato::RESPINTO,
            default => throw new DatiNonValidi('azione: approva, integrazioni o respingi.'),
        };
        if (EnteStato::VERIFICATO === $stato && (null === $profilo->getEmailConfermataIl() || EnteStato::EMAIL_DA_CONFERMARE === $profilo->getStato() || !$controlli['registro'])) {
            throw new Conflitto('Per approvare servono l\'email istituzionale confermata e il controllo sul registro pubblico.');
        }
        if (EnteStato::VERIFICATO !== $stato && null === $nota) {
            throw new DatiNonValidi('nota: spiega all\'ente cosa manca o perché è respinto.');
        }
        $profilo->decidi($stato, $admin, $nota, $controlli);
        $this->em->flush();
        $this->audit->log('ente.'.$azione, AuditOutcome::SUCCESS, resourceType: 'ente', resourceId: (string) $profilo->getTenant()->getId(), tenantId: $profilo->getTenant()->getId(), context: $controlli, actor: $admin);

        foreach ($this->proprietari($profilo->getTenant()) as $owner) {
            $this->posta->invia($owner->getEmail(), match ($stato) {
                    EnteStato::VERIFICATO => 'Il vostro ente è verificato su CartellaViva',
                    EnteStato::INTEGRAZIONI => 'Servono alcune integrazioni per verificare il vostro ente',
                    default => 'La registrazione del vostro ente non è stata approvata',
                }, 'emails/ente_decisione.html.twig', ['ente' => $profilo->getTenant()->getName(), 'stato' => $stato->value, 'nota' => $nota, 'url' => rtrim($this->frontendUrl, '/').'/ente']);
        }
    }

    /** "2026/27" da settembre, "2025/26" fino ad agosto. */
    public static function annoCorrente(\DateTimeImmutable $oggi): string
    {
        $inizio = (int) $oggi->format('n') >= 9 ? (int) $oggi->format('Y') : (int) $oggi->format('Y') - 1;

        return sprintf('%d/%02d', $inizio, ($inizio + 1) % 100);
    }

    /** @return list<User> */
    private function proprietari(Tenant $tenant): array
    {
        return array_values(array_map(
            static fn (Membership $m) => $m->getUser(),
            array_filter($this->memberships->findForTenant($tenant), static fn (Membership $m) => MembershipStatus::ACTIVE === $m->getStatus() && $m->getRole()->isOwnerRole()),
        ));
    }

    private function hash(EnteProfilo $profilo, string $codice): string
    {
        return hash('sha256', $profilo->getId()->toRfc4122().'|'.$codice);
    }
}
