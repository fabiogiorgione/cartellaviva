<?php

namespace App\Portale\Controller;

use App\Account\Entity\User;
use App\Portale\GestioneManuale;
use App\Portale\PlatformAccess;
use App\Shared\Doctrine\PerId;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Role;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Enum\TenantType;
use App\Tenancy\Repository\RoleRepository;
use App\Tenancy\TenantDeletion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestione manuale dal portale, solo superadmin:
 *   POST   /api/admin/famiglie                    {nome, proprietario:{email,nome?,cognome?}, membri:[{...,ruolo}]}
 *   POST   /api/admin/enti                        {ente:{...dati}, notaVerifica, proprietario, staff:[...]}
 *   GET    /api/admin/tenants/{id}/ruoli          ruoli assegnabili
 *   POST   /api/admin/tenants/{id}/membri         {email, nome?, cognome?, ruolo}
 *   PUT    /api/admin/tenants/{id}/membri/{mid}   {ruolo?, attivo?}
 *   DELETE /api/admin/tenants/{id}/membri/{mid}
 *   POST   /api/admin/utenti/{id}/attivazione     reinvia il link di attivazione
 *   PUT    /api/admin/utenti/{id}                 {nome, cognome, email}
 *   DELETE /api/admin/utenti/{id}                 anonimizza e disattiva
 *   POST   /api/admin/utenti/bulk                 {azione: blocca|sblocca|attivazione, ids}
 *   PUT    /api/admin/tenants/{id}                {nome} oppure i dati dell'ente
 *   DELETE /api/admin/tenants/{id}                {motivo}: eliminazione programmata
 *   POST   /api/admin/tenants/{id}/annulla-eliminazione
 *   GET    /api/admin/enti/{id}/gruppi            gruppi dell'anno in corso
 *   POST   /api/admin/enti/{id}/inviti-famiglia   {iscritto:{nome,cognome}, referente:{nome,cognome,email,relazione?}, gruppoId?}
 */
final class GestioneController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly PlatformAccess $platform,
        private readonly GestioneManuale $gestione,
        private readonly RoleRepository $roles,
        private readonly TenantDeletion $deletion,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/admin/famiglie', name: 'api_admin_famiglia_crea', methods: ['POST'])]
    public function creaFamiglia(Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $body = $this->body($request);
        $tenant = $this->gestione->creaFamiglia((string) ($body['nome'] ?? ''), $this->oggetto($body, 'proprietario'), $this->lista($body, 'membri'), $admin);

        return $this->json(['id' => (string) $tenant->getId(), 'nome' => $tenant->getName()], Response::HTTP_CREATED);
    }

    #[Route('/api/admin/enti', name: 'api_admin_ente_crea', methods: ['POST'])]
    public function creaEnte(Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $body = $this->body($request);
        $tenant = $this->gestione->creaEnte($this->oggetto($body, 'ente'), (string) ($body['notaVerifica'] ?? ''), $this->oggetto($body, 'proprietario'), $this->lista($body, 'staff'), $admin);

        return $this->json(['id' => (string) $tenant->getId(), 'nome' => $tenant->getName()], Response::HTTP_CREATED);
    }

    #[Route('/api/admin/tenants/{id}/ruoli', name: 'api_admin_tenant_ruoli', methods: ['GET'])]
    public function ruoli(string $id): JsonResponse
    {
        $this->platform->require(PlatformAccess::ADMIN);

        return $this->json(array_map(static fn (Role $r) => ['id' => (string) $r->getId(), 'slug' => $r->getSlug(), 'nome' => $r->getName(), 'proprietario' => $r->isOwnerRole()],
            $this->roles->findAssignableIn($this->tenant($id))));
    }

    #[Route('/api/admin/tenants/{id}/membri', name: 'api_admin_membro_aggiungi', methods: ['POST'])]
    public function aggiungi(string $id, Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $body = $this->body($request);
        $m = $this->gestione->aggiungiMembro($this->tenant($id), $body, (string) ($body['ruolo'] ?? ''), $admin);

        return $this->json(['membershipId' => (string) $m->getId(), 'userId' => (string) $m->getUser()->getId()], Response::HTTP_CREATED);
    }

    #[Route('/api/admin/tenants/{id}/membri/{mid}', name: 'api_admin_membro_modifica', methods: ['PUT'])]
    public function modifica(string $id, string $mid, Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $m = $this->membership($id, $mid);
        $body = $this->body($request);
        if (isset($body['ruolo']) && \is_string($body['ruolo'])) {
            $this->gestione->cambiaRuolo($m, $body['ruolo'], $admin);
        }
        if (isset($body['attivo']) && \is_bool($body['attivo'])) {
            $this->gestione->cambiaStato($m, $body['attivo'], $admin);
        }

        return $this->json(['ruolo' => $m->getRole()->getName(), 'stato' => $m->getStatus()->value]);
    }

    #[Route('/api/admin/tenants/{id}/membri/{mid}', name: 'api_admin_membro_rimuovi', methods: ['DELETE'])]
    public function rimuovi(string $id, string $mid): Response
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $this->gestione->rimuovi($this->membership($id, $mid), $admin);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/admin/utenti/{id}/attivazione', name: 'api_admin_utente_attivazione', methods: ['POST'])]
    public function attivazione(string $id): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $this->gestione->reinviaAttivazione($this->utente($id), $admin);

        return new JsonResponse(['messaggio' => 'Link di attivazione inviato di nuovo: vale 7 giorni.'], Response::HTTP_ACCEPTED);
    }

    #[Route('/api/admin/utenti/bulk', name: 'api_admin_utenti_bulk', methods: ['POST'], priority: 10)]
    public function bulkUtenti(Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $body = $this->body($request);
        $ids = \is_array($body['ids'] ?? null) ? array_values(array_filter($body['ids'], 'is_string')) : [];

        return $this->json($this->gestione->bulkUtenti((string) ($body['azione'] ?? ''), $ids, $admin));
    }

    #[Route('/api/admin/utenti/{id}', name: 'api_admin_utente_modifica', methods: ['PUT'])]
    public function modificaUtente(string $id, Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $user = $this->utente($id);
        $body = $this->body($request);
        $this->gestione->modificaUtente($user, (string) ($body['nome'] ?? $user->getFirstName()), (string) ($body['cognome'] ?? $user->getLastName()), (string) ($body['email'] ?? $user->getEmail()), $admin);

        return $this->json(['id' => (string) $user->getId(), 'email' => $user->getEmail(), 'nome' => $user->getFullName()]);
    }

    #[Route('/api/admin/utenti/{id}', name: 'api_admin_utente_elimina', methods: ['DELETE'])]
    public function eliminaUtente(string $id): Response
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $this->gestione->eliminaUtente($this->utente($id), $admin);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Corpo: {"nome": "..."} per le famiglie, i dati dell'ente per gli enti. */
    #[Route('/api/admin/tenants/{id}', name: 'api_admin_tenant_modifica', methods: ['PUT'])]
    public function modificaTenant(string $id, Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $tenant = $this->tenant($id);
        $this->gestione->modificaTenant($tenant, $this->body($request), $admin);

        return $this->json(['id' => (string) $tenant->getId(), 'nome' => $tenant->getName()]);
    }

    /** Eliminazione programmata (sola lettura per i giorni di attesa, annullabile). Corpo: {"motivo": "..."}. */
    #[Route('/api/admin/tenants/{id}', name: 'api_admin_tenant_elimina', methods: ['DELETE'])]
    public function eliminaTenant(string $id, Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $tenant = $this->tenant($id);
        $this->deletion->richiediDalPortale($tenant, $admin, (string) ($this->body($request)['motivo'] ?? ''));

        return new JsonResponse(['eliminazioneProgrammataIl' => $tenant->getEliminazioneProgrammataIl()?->format(\DATE_ATOM)], Response::HTTP_ACCEPTED);
    }

    #[Route('/api/admin/tenants/{id}/annulla-eliminazione', name: 'api_admin_tenant_annulla_eliminazione', methods: ['POST'])]
    public function annullaEliminazione(string $id): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $this->deletion->annullaDalPortale($this->tenant($id), $admin);

        return $this->json(['messaggio' => 'Eliminazione annullata.']);
    }

    #[Route('/api/admin/enti/{id}/gruppi', name: 'api_admin_ente_gruppi', methods: ['GET'])]
    public function gruppi(string $id): JsonResponse
    {
        $this->platform->require(PlatformAccess::ADMIN);

        return $this->json($this->gestione->gruppi($this->ente($id)));
    }

    #[Route('/api/admin/enti/{id}/inviti-famiglia', name: 'api_admin_ente_invito_famiglia', methods: ['POST'])]
    public function invitaFamiglia(string $id, Request $request): JsonResponse
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $body = $this->body($request);
        $gruppo = isset($body['gruppoId']) && \is_string($body['gruppoId']) ? $body['gruppoId'] : null;
        $this->gestione->invitaFamiglia($this->ente($id), $this->oggetto($body, 'iscritto'), $this->oggetto($body, 'referente'), $gruppo, $admin);

        return new JsonResponse(['messaggio' => 'Invito inviato: la famiglia si collega accettandolo dall\'email.'], Response::HTTP_CREATED);
    }

    // ---- Helpers ----------------------------------------------------------

    private function tenant(string $id): Tenant
    {
        $t = PerId::trova($this->em, Tenant::class, $id);

        return $t instanceof Tenant ? $t : throw new NotFoundHttpException('Tenant non trovato.');
    }

    private function utente(string $id): User
    {
        $u = PerId::trova($this->em, User::class, $id);

        return $u instanceof User ? $u : throw new NotFoundHttpException('Utente non trovato.');
    }

    private function ente(string $id): Tenant
    {
        $t = $this->tenant($id);

        return TenantType::AZIENDA === $t->getType() ? $t : throw new NotFoundHttpException('Ente non trovato.');
    }

    private function membership(string $tenantId, string $id): Membership
    {
        $tenant = $this->tenant($tenantId);
        $m = PerId::trova($this->em, Membership::class, $id);
        if (!$m instanceof Membership || !$m->getTenant()->getId()->equals($tenant->getId())) {
            throw new NotFoundHttpException('Membro non trovato.');
        }

        return $m;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function oggetto(array $body, string $chiave): array
    {
        $v = $body[$chiave] ?? null;

        return \is_array($v) ? $v : throw new UnprocessableEntityHttpException($chiave.': obbligatorio.');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function lista(array $body, string $chiave): array
    {
        $v = $body[$chiave] ?? [];
        if (!\is_array($v)) {
            throw new UnprocessableEntityHttpException($chiave.': deve essere un elenco.');
        }

        return array_values(array_filter($v, 'is_array'));
    }
}
