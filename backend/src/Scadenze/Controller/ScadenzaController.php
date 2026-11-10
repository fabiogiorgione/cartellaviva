<?php

namespace App\Scadenze\Controller;

use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\DocumentoRepository;
use App\Scadenze\ApiResource\ScadenzaResource;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Repository\ScadenzaRepository;
use App\Scadenze\ScadenzeService;
use App\Tenancy\Entity\MembroFamiglia;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\TenantAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Fatta": chiude la scadenza; se è ricorrente crea subito la successiva
 * (stesso proprietario, stessi collegamenti, promemoria riprogrammato).
 *
 * Scadenze proposte dai documenti: elenco e "ignora". Per accettarle l'app
 * crea una scadenza normale collegata al documento (ScadenzaProcessor chiude
 * la proposta).
 */
final class ScadenzaController extends AbstractController
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly ScadenzaRepository $scadenze,
        private readonly ScadenzeService $azioni,
        private readonly DocumentoRepository $documenti,
        private readonly DocumentService $documents,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/scadenze/{id}/fatta', name: 'api_scadenza_fatta', methods: ['POST'])]
    public function fatta(string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'scadenza:read');
        $scadenza = $this->scadenze->findOneInTenant($tenant, $id) ?? throw new NotFoundHttpException('Scadenza non trovata.');
        $this->access->require('scadenza:manage', $scadenza);
        $prossima = $this->azioni->segnaFatta($scadenza, $this->access->currentUser());

        return $this->json([
            'scadenza' => ScadenzaResource::fromEntity($scadenza),
            'prossima' => null !== $prossima ? ScadenzaResource::fromEntity($prossima) : null,
        ]);
    }

    /**
     * Proposte ancora da decidere sui documenti che l'utente può leggere, solo
     * se può creare scadenze. La data si decifra in memoria.
     */
    #[Route('/api/tenants/{tenantId}/scadenze-proposte', name: 'api_scadenze_proposte', methods: ['GET'])]
    public function proposte(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'scadenza:read');
        if (!$this->puoCreare($tenant)) {
            return $this->json([]);
        }
        $righe = [];
        foreach ($this->documenti->findBy(['tenant' => $tenant, 'scadenzaPropostaStato' => Documento::PROPOSTA_APERTA], ['createdAt' => 'DESC'], 100) as $d) {
            if (!$this->access->isGranted('documento:read', $d) || null === ($p = $this->documents->readScadenzaProposta($d))) {
                continue;
            }
            $righe[] = self::proposta($d, $p);
        }
        usort($righe, static fn ($a, $b) => $a['data'] <=> $b['data']);

        return $this->json($righe);
    }

    #[Route('/api/tenants/{tenantId}/documenti/{id}/scadenza-proposta/ignora', name: 'api_scadenza_proposta_ignora', methods: ['POST'])]
    public function ignora(string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'scadenza:read');
        $documento = $this->documenti->findOneInTenant($tenant, $id) ?? throw new NotFoundHttpException('Documento non trovato.');
        $this->access->require('documento:read', $documento);
        if (!$this->puoCreare($tenant)) {
            throw new AccessDeniedHttpException('Non puoi gestire le scadenze.');
        }
        $this->azioni->ignoraProposta($documento);

        return $this->json(['stato' => $documento->getScadenzaPropostaStato()]);
    }

    /** Stessa regola della creazione: scadenza:manage su una scadenza nuova dell'utente. */
    private function puoCreare(Tenant $tenant): bool
    {
        return $this->access->isGranted('scadenza:manage', new Scadenza($tenant, $this->access->currentUser(), '-', new \DateTimeImmutable('today')));
    }

    /**
     * @param array{data: string, tipo: ?string} $p
     *
     * @return array<string, mixed>
     */
    public static function proposta(Documento $d, array $p): array
    {
        return [
            'documentoId' => (string) $d->getId(),
            'documentoTitolo' => $d->getTitolo(),
            'data' => $p['data'],
            'tipo' => $p['tipo'],
            // Titolo suggerito: il tipo riconosciuto, altrimenti il titolo del documento.
            'titoloSuggerito' => mb_substr(sprintf('Scadenza %s', $p['tipo'] ?? $d->getTitolo()), 0, 200),
            'membri' => array_values(array_map(static fn (MembroFamiglia $m) => ['id' => (string) $m->getId(), 'nome' => $m->getDisplayName()], $d->getMembri()->toArray())),
            'categoriaId' => (string) $d->getCategoria()->getId(),
        ];
    }
}
