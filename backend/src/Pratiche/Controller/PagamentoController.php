<?php

namespace App\Pratiche\Controller;

use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\DocumentoRepository;
use App\Pratiche\Entity\Pagamento;
use App\Pratiche\Enum\PagamentoStato;
use App\Pratiche\PagamentiService;
use App\Pratiche\Repository\PagamentoRepository;
use App\Shared\Errore\DatiNonValidi;
use App\Shared\Errore\NonConsentito;
use App\Shared\Errore\NonTrovato;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pagamenti (Pratiche, fase 1): elenco, creazione, modifica, "pagato", e le
 * proposte che nascono dagli avvisi trovati nei documenti. Contratto in docs/pratiche.md.
 *
 * Un pagamento collegato a un documento che l'utente non può leggere (categoria
 * non consentita, accesso d'emergenza limitato) per lui non esiste: 404.
 */
final class PagamentoController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly TenantAccess $access,
        private readonly PagamentoRepository $pagamenti,
        private readonly PagamentiService $azioni,
        private readonly DocumentoRepository $documenti,
        private readonly DocumentService $documents,
        private readonly PermissionResolver $resolver,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/pagamenti', name: 'api_pagamenti_elenco', methods: ['GET'])]
    public function elenco(Request $request, string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');
        $membership = $this->membership($tenant);
        $stato = $request->query->get('stato');
        if (null !== $stato && '' !== $stato && null === PagamentoStato::tryFrom((string) $stato)) {
            throw new DatiNonValidi('Lo stato deve essere "da_pagare" o "pagato".');
        }

        $visibili = array_values(array_filter($this->pagamenti->allInTenant($tenant), fn (Pagamento $p) => $this->visibile($p, $membership)));
        $daPagare = array_values(array_filter($visibili, static fn (Pagamento $p) => PagamentoStato::DA_PAGARE === $p->getStato()));
        $pagati = array_values(array_filter($visibili, static fn (Pagamento $p) => PagamentoStato::PAGATO === $p->getStato()));
        // Da pagare: scadenza crescente, senza scadenza in fondo. Pagati: pagati per ultimi prima.
        usort($daPagare, static fn (Pagamento $a, Pagamento $b) => [null === $a->getScadenza(), $a->getScadenza()?->format('Y-m-d'), $a->getCreatedAt()] <=> [null === $b->getScadenza(), $b->getScadenza()?->format('Y-m-d'), $b->getCreatedAt()]);
        usort($pagati, static fn (Pagamento $a, Pagamento $b) => [$b->getPagatoIl(), $b->getCreatedAt()] <=> [$a->getPagatoIl(), $a->getCreatedAt()]);
        $centesimi = array_sum(array_map(static fn (Pagamento $p) => $p->getImportoCentesimi(), $daPagare));

        $elenco = match ($stato) {
            PagamentoStato::DA_PAGARE->value => $daPagare,
            PagamentoStato::PAGATO->value => $pagati,
            default => [...$daPagare, ...$pagati],
        };

        return $this->json([
            'pagamenti' => array_map(fn (Pagamento $p) => $this->vista($p, $membership), $elenco),
            'totaleDaPagare' => Pagamento::formattaImporto((int) $centesimi),
        ]);
    }

    #[Route('/api/tenants/{tenantId}/pagamenti/{id}', name: 'api_pagamento_dettaglio', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F\-]{36}'])]
    public function dettaglio(string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');

        return $this->json($this->vista($this->trova($tenant, $id), $this->membership($tenant)));
    }

    #[Route('/api/tenants/{tenantId}/pagamenti', name: 'api_pagamento_crea', methods: ['POST'])]
    public function crea(Request $request, string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');
        $pagamento = $this->azioni->crea($tenant, $this->access->currentUser(), $this->body($request));

        return $this->json($this->vista($pagamento, $this->membership($tenant)), Response::HTTP_CREATED);
    }

    #[Route('/api/tenants/{tenantId}/pagamenti/{id}', name: 'api_pagamento_modifica', methods: ['PATCH'])]
    public function modifica(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');
        $pagamento = $this->gestibile($tenant, $id);
        $this->azioni->aggiorna($pagamento, $this->access->currentUser(), $this->body($request));

        return $this->json($this->vista($pagamento, $this->membership($tenant)));
    }

    #[Route('/api/tenants/{tenantId}/pagamenti/{id}/pagato', name: 'api_pagamento_pagato', methods: ['POST'])]
    public function pagato(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');
        $pagamento = $this->gestibile($tenant, $id);
        $this->azioni->segnaPagato($pagamento, $this->access->currentUser(), $this->body($request));

        return $this->json($this->vista($pagamento, $this->membership($tenant)));
    }

    #[Route('/api/tenants/{tenantId}/pagamenti/{id}/da-pagare', name: 'api_pagamento_da_pagare', methods: ['POST'])]
    public function daPagare(string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');
        $pagamento = $this->gestibile($tenant, $id);
        $this->azioni->annullaPagato($pagamento);

        return $this->json($this->vista($pagamento, $this->membership($tenant)));
    }

    #[Route('/api/tenants/{tenantId}/pagamenti/{id}', name: 'api_pagamento_elimina', methods: ['DELETE'])]
    public function elimina(string $tenantId, string $id): Response
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');
        $this->azioni->elimina($this->gestibile($tenant, $id));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Avvisi trovati nei documenti e ancora da decidere, solo per chi può creare
     * pagamenti (gli altri ricevono un elenco vuoto) e solo dai documenti che può
     * leggere. I dati si decifrano in memoria.
     */
    #[Route('/api/tenants/{tenantId}/pagamenti-proposte', name: 'api_pagamenti_proposte', methods: ['GET'])]
    public function proposte(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');
        if (!$this->puoCreare($tenant)) {
            return $this->json(['proposte' => []]);
        }
        $righe = [];
        foreach ($this->documenti->findBy(['tenant' => $tenant, 'pagamentoPropostaStato' => Documento::PROPOSTA_APERTA], ['createdAt' => 'DESC'], 100) as $d) {
            if (!$this->access->isGranted('documento:read', $d) || null === ($p = $this->documents->readPagamentoProposta($d))) {
                continue;
            }
            $righe[] = self::proposta($d, $p);
        }
        usort($righe, static fn (array $a, array $b) => [null === $a['scadenza'], $a['scadenza']] <=> [null === $b['scadenza'], $b['scadenza']]);

        return $this->json(['proposte' => $righe]);
    }

    #[Route('/api/tenants/{tenantId}/documenti/{id}/pagamento-proposta/ignora', name: 'api_pagamento_proposta_ignora', methods: ['POST'])]
    public function ignora(string $tenantId, string $id): Response
    {
        $tenant = $this->access->requireTenant($tenantId, 'pagamento:read');
        $documento = $this->documenti->findOneInTenant($tenant, $id) ?? throw new NonTrovato('Documento non trovato.');
        $this->access->require('documento:read', $documento);
        if (!$this->puoCreare($tenant)) {
            throw new NonConsentito('Non puoi gestire i pagamenti.');
        }
        $this->azioni->ignoraProposta($documento);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Proposta di pagamento di un documento, nella forma dell'elenco delle proposte
     * (usata anche dal dettaglio del documento).
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    public static function proposta(Documento $d, array $p): array
    {
        $testo = static fn (string $k): ?string => \is_string($p[$k] ?? null) && '' !== $p[$k] ? $p[$k] : null;
        $categoria = $d->getCategoria();

        return [
            'documentoId' => (string) $d->getId(),
            'documentoTitolo' => $d->getTitolo(),
            'categoria' => ['id' => (string) $categoria->getId(), 'nome' => $categoria->getNome()],
            'titoloSuggerito' => $testo('titolo') ?? mb_substr($d->getTitolo(), 0, 200),
            'ente' => $testo('ente'),
            'importo' => $testo('importo'),
            'scadenza' => $testo('scadenza'),
            'codiceAvviso' => $testo('codiceAvviso'),
            'codiceFiscaleEnte' => $testo('codiceFiscaleEnte'),
        ];
    }

    /** Stessa regola della creazione: pagamento:manage su un pagamento nuovo dell'utente. */
    private function puoCreare(Tenant $tenant): bool
    {
        return $this->access->isGranted('pagamento:manage', new Pagamento($tenant, $this->access->currentUser(), '-', 1));
    }

    /** Pagamento visibile all'utente (404 altrimenti) che può anche modificare (403 altrimenti). */
    private function gestibile(Tenant $tenant, string $id): Pagamento
    {
        $pagamento = $this->trova($tenant, $id);
        $this->access->require('pagamento:manage', $pagamento);

        return $pagamento;
    }

    private function trova(Tenant $tenant, string $id): Pagamento
    {
        $pagamento = $this->pagamenti->findOneInTenant($tenant, $id);
        if (null === $pagamento || !$this->visibile($pagamento, $this->membership($tenant))) {
            throw new NonTrovato('Pagamento non trovato.');
        }

        return $pagamento;
    }

    private function membership(Tenant $tenant): ?Membership
    {
        return $this->resolver->getActiveMembership($this->access->currentUser(), $tenant);
    }

    /** Accesso d'emergenza limitato ad alcune categorie: i pagamenti dei documenti delle altre non esistono. */
    private function visibile(Pagamento $p, ?Membership $membership): bool
    {
        return null === $membership || null === $p->getDocumento() || $membership->consenteCategoria($p->getDocumento()->getCategoria()->getId());
    }

    /** @return array<string, mixed> */
    private function vista(Pagamento $p, ?Membership $membership): array
    {
        $doc = static fn (?Documento $d): ?array => null !== $d ? ['id' => (string) $d->getId(), 'titolo' => $d->getTitolo()] : null;
        $ricevuta = $p->getRicevuta();
        if (null !== $ricevuta && null !== $membership && !$membership->consenteCategoria($ricevuta->getCategoria()->getId())) {
            $ricevuta = null;
        }

        return [
            'id' => (string) $p->getId(),
            'titolo' => $p->getTitolo(),
            'ente' => $p->getEnte(),
            'importo' => $p->getImporto(),
            'scadenza' => $p->getScadenza()?->format('Y-m-d'),
            'codiceAvviso' => $p->getCodiceAvviso(),
            'codiceFiscaleEnte' => $p->getCodiceFiscaleEnte(),
            'stato' => $p->getStato()->value,
            'pagatoIl' => $p->getPagatoIl()?->format('Y-m-d'),
            'documento' => $doc($p->getDocumento()),
            'ricevuta' => $doc($ricevuta),
            'scadenzaId' => null !== $p->getScadenzaCollegata() ? (string) $p->getScadenzaCollegata()->getId() : null,
            'origine' => $p->getOrigine()->value,
            'createdAt' => $p->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
