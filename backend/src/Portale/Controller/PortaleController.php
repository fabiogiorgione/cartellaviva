<?php

namespace App\Portale\Controller;

use App\Account\Entity\User;
use App\Portale\Csv;
use App\Portale\ElenchiPortale;
use App\Portale\PlatformAccess;
use App\Portale\SaluteService;
use App\Portale\UtentiPortale;
use App\Shared\Audit\AuditLogger;
use App\Shared\Doctrine\PerId;
use App\Shared\Enum\AuditOutcome;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Portale della piattaforma: salute, famiglie ed enti, utenti, team.
 * Solo metadati: nessun endpoint sui contenuti delle famiglie.
 */
final class PortaleController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly PlatformAccess $platform,
        private readonly SaluteService $salute,
        private readonly ElenchiPortale $elenchi,
        private readonly UtentiPortale $utenti,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/api/admin/salute', name: 'api_admin_salute', methods: ['GET'])]
    public function salute(): JsonResponse
    {
        $this->platform->require(PlatformAccess::VEDERE);

        return $this->json($this->salute->stato());
    }

    /** ?tipo=privato|azienda&q=testo&stato=senza_proprietario|in_eliminazione */
    #[Route('/api/admin/tenants', name: 'api_admin_tenants', methods: ['GET'])]
    public function tenants(Request $request): JsonResponse
    {
        $this->platform->require(PlatformAccess::SUPPORTO);

        return $this->json($this->elenchi->tenants($request->query->all()));
    }

    /** Metadati di una famiglia o di un ente, con i membri (per scegliere il nuovo proprietario). */
    #[Route('/api/admin/tenants/{id}', name: 'api_admin_tenant', methods: ['GET'])]
    public function tenantDettaglio(string $id): JsonResponse
    {
        $this->platform->require(PlatformAccess::SUPPORTO);
        $tenant = PerId::trova($this->em, Tenant::class, $id) ?? throw new NotFoundHttpException('Tenant non trovato.');

        return $this->json($this->elenchi->dettaglioTenant($tenant));
    }

    #[Route('/api/admin/utenti', name: 'api_admin_utenti', methods: ['GET'])]
    public function utenti(Request $request): JsonResponse
    {
        $this->platform->require(PlatformAccess::SUPPORTO);

        return $this->json($this->elenchi->cercaUtenti((string) $request->query->get('q', '')));
    }

    #[Route('/api/admin/utenti/{id}', name: 'api_admin_utente', methods: ['GET'])]
    public function dettaglioUtente(string $id): JsonResponse
    {
        $this->platform->require(PlatformAccess::SUPPORTO);

        return $this->json($this->elenchi->rigaUtente($this->user($id), true));
    }

    /** Blocca o sblocca l'account; il blocco chiude anche tutte le sessioni. Corpo: {"attivo": bool, "motivo": "..."}. */
    #[Route('/api/admin/utenti/{id}/stato', name: 'api_admin_utente_stato', methods: ['POST'])]
    public function stato(string $id, Request $request): JsonResponse
    {
        $actor = $this->platform->require(PlatformAccess::SUPPORTO);
        $user = $this->user($id);
        $body = $this->body($request);
        $this->utenti->impostaAttivo($actor, $user, true === ($body['attivo'] ?? null), $this->str($body, 'motivo', 300));

        return $this->json($this->elenchi->rigaUtente($user, true));
    }

    #[Route('/api/admin/team', name: 'api_admin_team', methods: ['GET'])]
    public function team(): JsonResponse
    {
        $this->platform->require(PlatformAccess::ADMIN);

        return $this->json($this->elenchi->team());
    }

    /** Corpo: {"ruolo": "supporto"|"sola_lettura"|null}. Il superadmin non si assegna da qui. */
    #[Route('/api/admin/utenti/{id}/ruolo-piattaforma', name: 'api_admin_utente_ruolo', methods: ['PUT'])]
    public function ruolo(string $id, Request $request): JsonResponse
    {
        $actor = $this->platform->require(PlatformAccess::ADMIN);
        $user = $this->user($id);
        $ruolo = $this->body($request)['ruolo'] ?? null;
        $this->utenti->assegnaRuolo($actor, $user, \is_string($ruolo) ? $ruolo : null);

        return $this->json($this->elenchi->rigaUtente($user, false));
    }

    // ---- Elenchi paginati con filtri (liste del portale) -------------------

    /**
     * ?pagina=1&perPagina=25&nome=&tipo=privato|azienda&stato=attivo|senza_proprietario|in_eliminazione|ente_<stato>
     * &membro=(email o nome)&ordina=nome|creato&verso=asc|desc
     */
    #[Route('/api/admin/tenants/elenco', name: 'api_admin_tenants_elenco', methods: ['GET'], priority: 10)]
    public function elencoTenant(Request $request): JsonResponse
    {
        $this->platform->require(PlatformAccess::SUPPORTO);
        [$pagina, $perPagina] = $this->paginazione($request);

        return $this->json(['pagina' => $pagina, 'perPagina' => $perPagina] + $this->elenchi->paginaTenant($request->query->all(), $pagina, $perPagina));
    }

    #[Route('/api/admin/tenants/export', name: 'api_admin_tenants_export', methods: ['GET'], priority: 10)]
    public function exportTenant(Request $request): Response
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $righe = [['nome', 'tipo', 'stato', 'membri attivi', 'proprietari', 'spazio (byte)', 'creato il', 'eliminazione il']];
        foreach ($this->elenchi->tuttiTenant($request->query->all()) as $r) {
            $righe[] = [$r['nome'], 'privato' === $r['tipo'] ? 'famiglia' : 'ente', $r['stato'], (string) $r['membri'], implode(', ', $r['proprietari']),
                (string) $r['spazioBytes'], substr($r['createdAt'], 0, 10), (string) substr((string) $r['eliminazioneProgrammataIl'], 0, 10)];
        }
        $this->audit->log('portale.export', AuditOutcome::SUCCESS, context: ['cosa' => 'tenant', 'righe' => \count($righe) - 1], actor: $admin);

        return Csv::risposta('famiglie-enti', $righe);
    }

    /**
     * ?pagina=&perPagina=&nome=&email=&tenant=(nome)&tipo=privato|azienda|nessuno
     * &stato=attivi|bloccati|in_attesa|eliminati&team=si|no&dueFattori=si|no
     * &ordina=email|nome|ultimoAccesso|creato&verso=asc|desc
     */
    #[Route('/api/admin/utenti/elenco', name: 'api_admin_utenti_elenco', methods: ['GET'], priority: 10)]
    public function elencoUtenti(Request $request): JsonResponse
    {
        $this->platform->require(PlatformAccess::SUPPORTO);
        [$pagina, $perPagina] = $this->paginazione($request);

        return $this->json(['pagina' => $pagina, 'perPagina' => $perPagina] + $this->elenchi->paginaUtenti($request->query->all(), $pagina, $perPagina));
    }

    #[Route('/api/admin/utenti/export', name: 'api_admin_utenti_export', methods: ['GET'], priority: 10)]
    public function exportUtenti(Request $request): Response
    {
        $admin = $this->platform->require(PlatformAccess::ADMIN);
        $righe = [['email', 'nome', 'stato', 'portale', 'verifica in due passaggi', 'ultimo accesso', 'creato il', 'famiglie ed enti']];
        foreach ($this->elenchi->tuttiUtenti($request->query->all()) as $u) {
            $righe[] = [$u['email'], $u['nome'], $u['stato'], (string) $u['ruoloPiattaforma'], $u['dueFattori'] ? 'sì' : 'no',
                (string) substr((string) $u['ultimoAccesso'], 0, 10), substr($u['createdAt'], 0, 10),
                implode(', ', array_map(static fn ($a) => sprintf('%s (%s)', $a['tenant'], $a['ruolo']), $u['appartenenze']))];
        }
        $this->audit->log('portale.export', AuditOutcome::SUCCESS, context: ['cosa' => 'utenti', 'righe' => \count($righe) - 1], actor: $admin);

        return Csv::risposta('utenti', $righe);
    }

    /** @return array{int, int} */
    private function paginazione(Request $request): array
    {
        $perPagina = (int) $request->query->get('perPagina', 25);

        return [max(1, (int) $request->query->get('pagina', 1)), \in_array($perPagina, ElenchiPortale::PER_PAGINA, true) ? $perPagina : 25];
    }

    private function user(string $id): User
    {
        return PerId::trova($this->em, User::class, $id) ?? throw new NotFoundHttpException('Utente non trovato.');
    }
}
