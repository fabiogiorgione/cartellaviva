<?php

namespace App\Pratiche\Controller;

use App\Documenti\DocumentService;
use App\Documenti\Repository\CategoriaRepository;
use App\Pratiche\CandidatiPratica;
use App\Pratiche\ControlliPratica;
use App\Pratiche\Entity\Pratica;
use App\Pratiche\Entity\PraticaVoce;
use App\Pratiche\Enum\PraticaStato;
use App\Pratiche\Enum\VoceStato;
use App\Pratiche\Modello\ModelliPratica;
use App\Pratiche\Modello\ModelloPratica;
use App\Pratiche\PacchettoPratica;
use App\Pratiche\PraticaCifratura;
use App\Pratiche\PraticheService;
use App\Pratiche\Repository\PraticaRepository;
use App\Shared\Errore\NonTrovato;
use App\Shared\Http\JsonBodyTrait;
use App\Shared\Http\RispostaFile;
use App\Tenancy\Entity\Tenant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Tenancy\Security\TenantAccess;

/**
 * Pratiche (fase 2a: motore comune e 730). Contratto in docs/pratiche.md.
 *
 * Le voci che vengono da documenti che l'utente non può leggere per lui non
 * esistono (non compaiono, non contano nelle somme, non vanno nel pacchetto).
 */
final class PraticaController extends AbstractController
{
    use JsonBodyTrait;

    private const ID = ['id' => '[0-9a-fA-F\-]{36}', 'voceId' => '[0-9a-fA-F\-]{36}'];

    public function __construct(
        private readonly TenantAccess $access,
        private readonly PraticaRepository $pratiche,
        private readonly PraticheService $azioni,
        private readonly ModelliPratica $modelli,
        private readonly CandidatiPratica $candidati,
        private readonly PacchettoPratica $pacchetto,
        private readonly PraticaCifratura $cifratura,
        private readonly DocumentService $documents,
        private readonly CategoriaRepository $categorie,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/pratiche-modelli', name: 'api_pratiche_modelli', methods: ['GET'])]
    public function modelli(string $tenantId): JsonResponse
    {
        $this->access->requireTenant($tenantId, 'pratica:read');
        $out = [];
        foreach ($this->modelli->tutti() as $m) {
            $out[] = $m->vista(false) + ['proposto' => $this->modelli->annoProposto($m->codice) === $m->anno];
        }

        return $this->json(['modelli' => $out]);
    }

    #[Route('/api/tenants/{tenantId}/pratiche', name: 'api_pratiche_elenco', methods: ['GET'])]
    public function elenco(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pratica:read');
        $out = [];
        foreach ($this->pratiche->allInTenant($tenant) as $p) {
            $modello = $this->modelli->trova($p->getModello(), $p->getAnno());
            if (null !== $modello) {
                $out[] = $this->riassunto($modello, $p, $this->azioni->vociVisibili($p));
            }
        }

        return $this->json(['pratiche' => $out, 'puoAvviare' => $this->puoAvviare($tenant)]);
    }

    #[Route('/api/tenants/{tenantId}/pratiche', name: 'api_pratica_avvia', methods: ['POST'])]
    public function avvia(Request $request, string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pratica:read');
        $this->access->require('pratica:manage', new Pratica($tenant, $this->access->currentUser(), '-', 2000, '', 1));
        $pratica = $this->azioni->avvia($tenant, $this->access->currentUser(), $this->body($request));

        return $this->json($this->dettaglioDi($pratica), Response::HTTP_CREATED);
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}', name: 'api_pratica_dettaglio', methods: ['GET'], requirements: self::ID)]
    public function dettaglio(string $tenantId, string $id): JsonResponse
    {
        return $this->json($this->dettaglioDi($this->trova($tenantId, $id)));
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}', name: 'api_pratica_modifica', methods: ['PATCH'], requirements: self::ID)]
    public function modifica(Request $request, string $tenantId, string $id): JsonResponse
    {
        $pratica = $this->gestibile($tenantId, $id);
        $this->azioni->aggiorna($pratica, $this->access->currentUser(), $this->body($request));

        return $this->json($this->dettaglioDi($pratica));
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}', name: 'api_pratica_elimina', methods: ['DELETE'], requirements: self::ID)]
    public function elimina(string $tenantId, string $id): Response
    {
        $this->azioni->elimina($this->gestibile($tenantId, $id));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}/consenso-cifrati', name: 'api_pratica_consenso', methods: ['POST'], requirements: self::ID)]
    public function consenso(Request $request, string $tenantId, string $id): JsonResponse
    {
        $pratica = $this->gestibile($tenantId, $id);
        $this->azioni->consensoCifrati($pratica, $this->access->currentUser(), false !== ($this->body($request)['consenso'] ?? true));

        return $this->json($this->dettaglioDi($pratica));
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}/analizza', name: 'api_pratica_analizza', methods: ['POST'], requirements: self::ID)]
    public function analizza(string $tenantId, string $id): JsonResponse
    {
        $pratica = $this->gestibile($tenantId, $id);

        return $this->json(['inCoda' => $this->azioni->analizza($pratica)]);
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}/non-serve', name: 'api_pratica_non_serve', methods: ['POST'], requirements: self::ID)]
    public function nonServe(Request $request, string $tenantId, string $id): JsonResponse
    {
        $pratica = $this->gestibile($tenantId, $id);
        $b = $this->body($request);
        $this->azioni->nonServe($pratica, $b['requisito'] ?? null, $b['personaId'] ?? null, false !== ($b['nonServe'] ?? true), $b['dettaglio'] ?? null);

        return $this->json($this->dettaglioDi($pratica));
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}/voci', name: 'api_pratica_voce_crea', methods: ['POST'], requirements: self::ID)]
    public function creaVoce(Request $request, string $tenantId, string $id): JsonResponse
    {
        $pratica = $this->gestibile($tenantId, $id);
        $voce = $this->azioni->creaVoce($pratica, $this->body($request));

        return $this->json($this->voceDi($pratica, $voce), Response::HTTP_CREATED);
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}/voci/{voceId}', name: 'api_pratica_voce_modifica', methods: ['PATCH'], requirements: self::ID)]
    public function modificaVoce(Request $request, string $tenantId, string $id, string $voceId): JsonResponse
    {
        [$pratica, $voce] = $this->voceGestibile($tenantId, $id, $voceId);
        $this->azioni->modificaVoce($voce, $this->body($request));

        return $this->json($this->voceDi($pratica, $voce));
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}/voci/{voceId}/{azione}', name: 'api_pratica_voce_stato', methods: ['POST'], requirements: self::ID + ['azione' => 'conferma|escludi|riproponi'])]
    public function statoVoce(string $tenantId, string $id, string $voceId, string $azione): JsonResponse
    {
        [$pratica, $voce] = $this->voceGestibile($tenantId, $id, $voceId);
        $this->azioni->statoVoce($voce, match ($azione) {
            'conferma' => VoceStato::CONFERMATA,
            'escludi' => VoceStato::ESCLUSA,
            default => VoceStato::PROPOSTA,
        });

        return $this->json($this->voceDi($pratica, $voce));
    }

    #[Route('/api/tenants/{tenantId}/pratiche/{id}/voci/{voceId}', name: 'api_pratica_voce_elimina', methods: ['DELETE'], requirements: self::ID)]
    public function eliminaVoce(string $tenantId, string $id, string $voceId): Response
    {
        [, $voce] = $this->voceGestibile($tenantId, $id, $voceId);
        $this->azioni->eliminaVoce($voce);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Analisi sull'iPhone: i prossimi documenti da analizzare, con le parti utili
     * del testo entro [caratteri] (dal contesto del modello del telefono).
     */
    #[Route('/api/tenants/{tenantId}/pratiche/{id}/da-analizzare', name: 'api_pratica_da_analizzare', methods: ['GET'], requirements: self::ID)]
    public function daAnalizzare(Request $request, string $tenantId, string $id): JsonResponse
    {
        $pratica = $this->gestibile($tenantId, $id);
        if (!$pratica->analisiSulTelefono() || PraticaStato::IN_CORSO !== $pratica->getStato()) {
            return $this->json(['lavori' => [], 'restano' => 0]);
        }
        $caratteri = max(1500, min(20000, $request->query->getInt('caratteri', 6000)));
        $limite = max(1, min(10, $request->query->getInt('limite', 3)));

        return $this->json($this->azioni->lavoriTelefono($pratica, $caratteri, $limite));
    }

    /** Valori letti sul telefono per un documento: il server li ricontrolla e li salva come proposte. */
    #[Route('/api/tenants/{tenantId}/pratiche/{id}/risultati', name: 'api_pratica_risultati', methods: ['POST'], requirements: self::ID)]
    public function risultati(Request $request, string $tenantId, string $id): JsonResponse
    {
        $pratica = $this->gestibile($tenantId, $id);
        if (!$pratica->analisiSulTelefono()) {
            throw new \App\Shared\Errore\Conflitto('Questa pratica si analizza sul server.');
        }

        return $this->json(['voci' => $this->azioni->risultatoTelefono($pratica, $this->body($request))]);
    }

    /** ZIP generato al momento: niente copie sul server. */
    #[Route('/api/tenants/{tenantId}/pratiche/{id}/pacchetto', name: 'api_pratica_pacchetto', methods: ['GET'], requirements: self::ID)]
    public function scaricaPacchetto(string $tenantId, string $id): Response
    {
        $pratica = $this->trova($tenantId, $id);
        $modello = $this->azioni->modello($pratica);
        $voci = $this->azioni->vociVisibili($pratica);
        $manca = ControlliPratica::cosaManca($modello, $pratica, $voci, $this->candidati->scoperti($modello, $pratica));
        $this->azioni->log('pratica.pacchetto', $pratica, ['archiviato' => false]);
        $response = new StreamedResponse(function () use ($modello, $pratica, $voci, $manca): void {
            $this->pacchetto->scrivi($modello, $pratica, $voci, $manca, fopen('php://output', 'w'));
        });

        return RispostaFile::intestazioni($response, 'application/zip', PacchettoPratica::nomeFile($modello));
    }

    /** "Archivia": il pacchetto diventa un documento cifrato in "Condivisi con enti". */
    #[Route('/api/tenants/{tenantId}/pratiche/{id}/archivia', name: 'api_pratica_archivia', methods: ['POST'], requirements: self::ID)]
    public function archivia(string $tenantId, string $id): JsonResponse
    {
        $pratica = $this->gestibile($tenantId, $id);
        $tenant = $pratica->getTenant();
        $this->access->require('documento:create', $tenant);
        $modello = $this->azioni->modello($pratica);
        $voci = $this->azioni->vociVisibili($pratica);
        $buffer = fopen('php://temp', 'w+');
        $this->pacchetto->scrivi($modello, $pratica, $voci, ControlliPratica::cosaManca($modello, $pratica, $voci, $this->candidati->scoperti($modello, $pratica)), $buffer);
        rewind($buffer);
        $zip = (string) stream_get_contents($buffer);
        fclose($buffer);
        $categoria = $this->categorie->findSystemBySlug('condivisi_enti') ?? throw new NonTrovato('Categoria "Condivisi con enti" mancante: esegui app:categorie:sync.');
        $doc = $this->documents->upload($this->access->currentUser(), $tenant, $categoria, $pratica->getPersone(), $zip, PacchettoPratica::nomeFile($modello), 'Pacchetto '.$modello->titolo, new \DateTimeImmutable('today'))['documento'];
        $this->azioni->log('pratica.pacchetto', $pratica, ['archiviato' => true]);

        return $this->json(['documento' => ['id' => (string) $doc->getId(), 'titolo' => $doc->getTitolo()]], Response::HTTP_CREATED);
    }

    /**
     * Voci proposte di tutte le pratiche che l'utente può gestire, per la coda
     * "Da confermare". Chi non può gestire le pratiche riceve un elenco vuoto.
     */
    #[Route('/api/tenants/{tenantId}/pratiche-proposte', name: 'api_pratiche_proposte', methods: ['GET'])]
    public function proposte(string $tenantId): JsonResponse
    {
        $tenant = $this->access->requireTenant($tenantId, 'pratica:read');
        $out = [];
        foreach ($this->pratiche->allInTenant($tenant) as $p) {
            $modello = $this->modelli->trova($p->getModello(), $p->getAnno());
            if (null === $modello || PraticaStato::IN_CORSO !== $p->getStato() || !$this->access->isGranted('pratica:manage', $p)) {
                continue;
            }
            $voci = $this->azioni->vociVisibili($p);
            foreach ($voci as $x) {
                if (VoceStato::PROPOSTA === $x['voce']->getStato()) {
                    $r = $modello->requisito($x['voce']->getRequisito());
                    $out[] = $this->vistaVoce($modello, $x['voce'], $x['valori'], $voci) + [
                        'praticaId' => (string) $p->getId(),
                        'praticaTitolo' => $modello->titolo,
                        'requisitoEtichetta' => $r->etichetta ?? $x['voce']->getRequisito(),
                        'tipoEtichetta' => $r?->tipo(\is_string($x['valori']['tipo'] ?? null) ? $x['valori']['tipo'] : null)?->etichetta,
                    ];
                }
            }
        }

        return $this->json(['proposte' => $out]);
    }

    // ---- Helpers ----------------------------------------------------------

    private function puoAvviare(Tenant $tenant): bool
    {
        return $this->access->isGranted('pratica:manage', new Pratica($tenant, $this->access->currentUser(), '-', 2000, '', 1));
    }

    private function trova(string $tenantId, string $id): Pratica
    {
        $tenant = $this->access->requireTenant($tenantId, 'pratica:read');

        return $this->pratiche->findOneInTenant($tenant, $id) ?? throw new NonTrovato('Pratica non trovata.');
    }

    private function gestibile(string $tenantId, string $id): Pratica
    {
        $pratica = $this->trova($tenantId, $id);
        $this->access->require('pratica:manage', $pratica);

        return $pratica;
    }

    /** @return array{0: Pratica, 1: PraticaVoce} */
    private function voceGestibile(string $tenantId, string $id, string $voceId): array
    {
        $pratica = $this->gestibile($tenantId, $id);
        foreach ($this->azioni->vociVisibili($pratica) as $x) {
            if ($x['voce']->getId()->toRfc4122() === strtolower($voceId)) {
                return [$pratica, $x['voce']];
            }
        }
        throw new NonTrovato('Voce non trovata.');
    }

    /** @return array<string, mixed> */
    private function voceDi(Pratica $pratica, PraticaVoce $voce): array
    {
        $voci = $this->azioni->vociVisibili($pratica);

        return $this->vistaVoce($this->azioni->modello($pratica), $voce, $this->cifratura->decifra($voce), $voci);
    }

    /**
     * @param array<string, mixed>                                      $valori
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $tutte
     *
     * @return array<string, mixed>
     */
    private function vistaVoce(ModelloPratica $modello, PraticaVoce $v, array $valori, array $tutte): array
    {
        $d = $v->getDocumento();

        return [
            'id' => (string) $v->getId(),
            'requisito' => $v->getRequisito(),
            'personaId' => $v->getPersona()?->getId()->toRfc4122(),
            'persona' => $v->getPersona()?->getDisplayName(),
            'documento' => null !== $d ? ['id' => (string) $d->getId(), 'titolo' => $d->getTitolo(), 'cifrato' => $d->isCifrato()] : null,
            'valori' => (object) $valori,
            'stato' => $v->getStato()->value,
            'origine' => $v->getOrigine()->value,
            'controlli' => ControlliPratica::voce($modello, $v, $valori, $tutte),
            'createdAt' => $v->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @param list<array{voce: PraticaVoce, valori: array<string, mixed>}> $voci
     *
     * @return array<string, mixed>
     */
    private function riassunto(ModelloPratica $modello, Pratica $p, array $voci, ?array $manca = null): array
    {
        $manca ??= ControlliPratica::cosaManca($modello, $p, $voci, $this->candidati->scoperti($modello, $p));
        $conta = static fn (VoceStato $s): int => \count(array_filter($voci, static fn (array $x) => $s === $x['voce']->getStato()));

        return [
            'id' => (string) $p->getId(),
            'modello' => $p->getModello(),
            'anno' => $p->getAnno(),
            'titolo' => $modello->titolo,
            'stato' => $p->getStato()->value,
            'provvisorio' => $modello->provvisorio(),
            'persone' => array_map(static fn ($m) => ['id' => $m->getId()->toRfc4122(), 'nome' => $m->getDisplayName()], $p->getPersone()),
            'confermate' => $conta(VoceStato::CONFERMATA),
            'proposte' => $conta(VoceStato::PROPOSTA),
            'mancanti' => \count($manca),
            'annoRiferimento' => $modello->annoRiferimento(),
            'totaleConfermato' => ControlliPratica::riepilogo($modello, $voci)['totale'],
            'createdAt' => $p->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function dettaglioDi(Pratica $p): array
    {
        $modello = $this->azioni->modello($p);
        $voci = $this->azioni->vociVisibili($p);
        $prima = $this->pratiche->annoPrima($p);
        $stato = PraticaStato::IN_CORSO === $p->getStato() ? $this->candidati->stato($modello, $p) : ['daAnalizzare' => [], 'analizzati' => 0, 'cifratiInAttesa' => 0];
        $nonServe = [];
        foreach ($p->getNonServe() as $k) {
            [$r, $persona] = array_pad(explode('|', $k, 2), 2, null);
            [$r, $dettaglio] = array_pad(explode(':', $r, 2), 2, null);
            $nonServe[] = ['requisito' => $r, 'personaId' => $persona, 'dettaglio' => $dettaglio];
        }
        $manca = ControlliPratica::cosaManca($modello, $p, $voci, $this->candidati->scoperti($modello, $p));

        return $this->riassunto($modello, $p, $voci, $manca) + [
            'modelloInfo' => $modello->vista(),
            'consensoCifrati' => $p->haConsensoCifrati() ? ['da' => $p->getConsensoCifratiDa()?->getFullName(), 'il' => $p->getConsensoCifratiIl()?->format(\DATE_ATOM)] : null,
            'nonServe' => $nonServe,
            'voci' => array_map(fn (array $x) => $this->vistaVoce($modello, $x['voce'], $x['valori'], $voci), $voci),
            'cosaManca' => array_map(static fn (array $m) => ['valori' => (object) $m['valori']] + $m, $manca),
            'scadenzaRinnovo' => null !== $p->getScadenzaRinnovo() ? ['id' => (string) $p->getScadenzaRinnovo()->getId(), 'data' => $p->getScadenzaRinnovo()->getData()->format('Y-m-d')] : null,
            'annoPrima' => null !== $prima ? ControlliPratica::annoPrima($modello, $this->azioni->vociVisibili($prima), $voci) : [],
            'riepilogo' => ControlliPratica::riepilogo($modello, $voci),
            'analisi' => ['inCoda' => \count($stato['daAnalizzare']), 'analizzati' => $stato['analizzati'], 'cifratiInAttesa' => $stato['cifratiInAttesa']],
            'puoGestire' => $this->access->isGranted('pratica:manage', $p),
            'analisiSu' => $p->getAnalisiSu(),
            'ambito' => $this->vistaAmbito($p),
        ];
    }

    /** @return array{categorie: list<array{id: string, nome: string}>, etichette: list<string>, tutti: bool}|null */
    private function vistaAmbito(Pratica $p): ?array
    {
        $a = $p->getAmbito();
        if (null === $a) {
            return null;
        }
        $categorie = [];
        foreach ($a['categorie'] as $id) {
            $c = $this->categorie->findUsableById($p->getTenant(), $id);
            // Una categoria cancellata nel frattempo semplicemente non conta più.
            if (null !== $c) {
                $categorie[] = ['id' => (string) $c->getId(), 'nome' => $c->getNome()];
            }
        }

        return ['categorie' => $categorie, 'etichette' => $a['etichette'], 'tutti' => $a['tutti']];
    }
}
