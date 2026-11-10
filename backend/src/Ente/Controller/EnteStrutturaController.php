<?php

namespace App\Ente\Controller;

use App\Ente\EnteAccess;
use App\Ente\EnteJson;
use App\Ente\Repository\AnnoEnteRepository;
use App\Ente\Repository\IscrittoRepository;
use App\Ente\StrutturaEnteService;
use App\Ente\Entity\AnnoEnte;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Sede;
use App\Shared\Doctrine\PerId;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Anni, sedi, gruppi e operatori dei gruppi. Leggere: ente:read (i gruppi
 * secondo l'ambito dell'operatore); modificare: ente:manage.
 */
final class EnteStrutturaController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly EnteAccess $access,
        private readonly EntityManagerInterface $em,
        private readonly StrutturaEnteService $struttura,
        private readonly AnnoEnteRepository $anni,
        private readonly IscrittoRepository $iscritti,
    ) {
    }

    // ---- Anni -------------------------------------------------------------

    #[Route('/api/enti/{tenantId}/anni', name: 'api_ente_anni', methods: ['GET'])]
    public function anni(string $tenantId): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:read');

        return $this->json(array_map(EnteJson::anno(...), $this->em->getRepository(AnnoEnte::class)->findBy(['tenant' => $ente], ['createdAt' => 'DESC', 'id' => 'DESC'])));
    }

    /**
     * Passaggio all'anno successivo: corpo {"nome": "2027/28", "gruppi": [{"id": "...", "nuovoNome": "4ªB"|null}]}.
     * Ogni gruppo indicato con un nuovo nome passa al nuovo anno con i suoi iscritti
     * e operatori (null = il gruppo esce, es. le quinte); i gruppi non indicati
     * restano solo nell'anno archiviato. Le correzioni (bocciature, trasferimenti)
     * si fanno poi a mano.
     */
    #[Route('/api/enti/{tenantId}/anni/{annoId}/passaggio', name: 'api_ente_anno_passaggio', methods: ['POST'])]
    public function passaggio(string $tenantId, string $annoId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $anno = $this->load(AnnoEnte::class, $ente, $annoId, 'Anno non trovato.');
        $body = $this->body($request);
        $nome = $this->str($body, 'nome', 20) ?? throw new UnprocessableEntityHttpException('nome: indica il nuovo anno, es. 2027/28.');
        $nuoviNomi = [];
        foreach (\is_array($body['gruppi'] ?? null) ? $body['gruppi'] : [] as $voce) {
            if (\is_array($voce) && \is_string($voce['id'] ?? null)) {
                $nuoviNomi[$voce['id']] = $this->str($voce, 'nuovoNome', 80);
            }
        }

        return $this->json(EnteJson::anno($this->struttura->passaggioAnno($ente, $anno, $nome, $nuoviNomi)), Response::HTTP_CREATED);
    }

    // ---- Sedi -------------------------------------------------------------

    #[Route('/api/enti/{tenantId}/sedi', name: 'api_ente_sedi', methods: ['GET'])]
    public function sedi(string $tenantId): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:read');

        return $this->json(array_map(EnteJson::sede(...), $this->em->getRepository(Sede::class)->findBy(['tenant' => $ente], ['nome' => 'ASC'])));
    }

    #[Route('/api/enti/{tenantId}/sedi', name: 'api_ente_sede_crea', methods: ['POST'])]
    public function creaSede(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $body = $this->body($request);
        $sede = $this->struttura->creaSede($ente, $this->str($body, 'nome', 120) ?? throw new UnprocessableEntityHttpException('nome: obbligatorio.'), $this->str($body, 'indirizzo'));

        return $this->json(EnteJson::sede($sede), Response::HTTP_CREATED);
    }

    #[Route('/api/enti/{tenantId}/sedi/{id}', name: 'api_ente_sede_aggiorna', methods: ['PUT'])]
    public function aggiornaSede(string $tenantId, string $id, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $sede = $this->load(Sede::class, $ente, $id, 'Sede non trovata.');
        $body = $this->body($request);
        $this->struttura->aggiornaSede($sede, $this->str($body, 'nome', 120) ?? throw new UnprocessableEntityHttpException('nome: obbligatorio.'), $this->str($body, 'indirizzo'));

        return $this->json(EnteJson::sede($sede));
    }

    #[Route('/api/enti/{tenantId}/sedi/{id}', name: 'api_ente_sede_elimina', methods: ['DELETE'])]
    public function eliminaSede(string $tenantId, string $id): Response
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $this->struttura->eliminaSede($this->load(Sede::class, $ente, $id, 'Sede non trovata.'));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    // ---- Gruppi -----------------------------------------------------------

    /** ?anno=<id> (default: l'anno in corso). Un operatore vede solo i suoi gruppi. */
    #[Route('/api/enti/{tenantId}/gruppi', name: 'api_ente_gruppi', methods: ['GET'])]
    public function gruppi(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:read');
        $anno = $request->query->has('anno')
            ? $this->load(AnnoEnte::class, $ente, (string) $request->query->get('anno'), 'Anno non trovato.')
            : $this->anni->corrente($ente);
        if (null === $anno) {
            return $this->json([]);
        }
        $visibili = $this->access->gruppiVisibili($ente);
        $gruppi = array_filter(
            $this->em->getRepository(Gruppo::class)->findBy(['anno' => $anno], ['nome' => 'ASC']),
            static fn (Gruppo $g) => null === $visibili || \in_array((string) $g->getId(), $visibili, true),
        );
        $conteggi = $this->iscritti->conteggiPerGruppo($ente);

        return $this->json(array_values(array_map(static fn (Gruppo $g) => EnteJson::gruppo($g, $conteggi[(string) $g->getId()] ?? 0), $gruppi)));
    }

    #[Route('/api/enti/{tenantId}/gruppi', name: 'api_ente_gruppo_crea', methods: ['POST'])]
    public function creaGruppo(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $body = $this->body($request);
        $gruppo = $this->struttura->creaGruppo($ente, $this->nomeGruppo($body), $this->str($body, 'tipo', 20) ?? Gruppo::TIPO_CLASSE, $this->sedeDa($ente, $body));

        return $this->json(EnteJson::gruppo($gruppo, 0), Response::HTTP_CREATED);
    }

    #[Route('/api/enti/{tenantId}/gruppi/{id}', name: 'api_ente_gruppo_aggiorna', methods: ['PUT'])]
    public function aggiornaGruppo(string $tenantId, string $id, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $gruppo = $this->load(Gruppo::class, $ente, $id, 'Gruppo non trovato.');
        $body = $this->body($request);
        $this->struttura->aggiornaGruppo($gruppo, $this->nomeGruppo($body), $this->str($body, 'tipo', 20) ?? Gruppo::TIPO_CLASSE, $this->sedeDa($ente, $body));

        return $this->json(EnteJson::gruppo($gruppo));
    }

    #[Route('/api/enti/{tenantId}/gruppi/{id}', name: 'api_ente_gruppo_elimina', methods: ['DELETE'])]
    public function eliminaGruppo(string $tenantId, string $id): Response
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $this->struttura->eliminaGruppo($this->load(Gruppo::class, $ente, $id, 'Gruppo non trovato.'));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Corpo {"membershipIds": [...]}: operatori (membri attivi dell'ente) assegnati al gruppo. */
    #[Route('/api/enti/{tenantId}/gruppi/{id}/operatori', name: 'api_ente_gruppo_operatori', methods: ['PUT'])]
    public function operatori(string $tenantId, string $id, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'ente:manage');
        $gruppo = $this->load(Gruppo::class, $ente, $id, 'Gruppo non trovato.');
        $ids = array_filter(\is_array($this->body($request)['membershipIds'] ?? null) ? $this->body($request)['membershipIds'] : [], static fn ($v) => \is_string($v) && Uuid::isValid($v));
        $operatori = array_values(array_map(fn (string $mid) => $this->em->find(Membership::class, Uuid::fromString($mid)), $ids));
        $this->struttura->assegnaOperatori($ente, $gruppo, $operatori);

        return $this->json(EnteJson::gruppo($gruppo));
    }

    // ---- Helpers ----------------------------------------------------------

    private function nomeGruppo(array $body): string
    {
        return $this->str($body, 'nome', 80) ?? throw new UnprocessableEntityHttpException('nome: obbligatorio (es. 3ªB, Minivolley).');
    }

    private function sedeDa(Tenant $ente, array $body): ?Sede
    {
        $id = $this->str($body, 'sedeId', 36);

        return null === $id ? null : $this->load(Sede::class, $ente, $id, 'Sede non trovata.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function load(string $class, Tenant $ente, string $id, string $notFound): object
    {
        $entity = PerId::trova($this->em, $class, $id);
        if (null === $entity || !$entity->getTenant()->getId()->equals($ente->getId())) {
            throw new NotFoundHttpException($notFound);
        }
        if ($entity instanceof Gruppo) {
            $this->access->assertGruppo($entity);
        }

        return $entity;
    }
}
