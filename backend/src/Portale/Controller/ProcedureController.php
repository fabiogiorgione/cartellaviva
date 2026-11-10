<?php

namespace App\Portale\Controller;

use App\Portale\Entity\ProceduraAccesso;
use App\Portale\PlatformAccess;
use App\Portale\ProcedureService;
use App\Shared\Doctrine\PerId;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Procedure d'accesso: richiesta e conferma del recupero (pubbliche),
 * annullamento dal link dell'avviso (pubblico), gestione del supporto.
 */
final class ProcedureController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly ProcedureService $procedure,
        private readonly PlatformAccess $platform,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.public_auth')]
        private readonly RateLimiterFactoryInterface $publicAuthLimiter,
    ) {
    }

    private function limita(Request $request): void
    {
        if (!$this->publicAuthLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }
    }

    /** PUBBLICO. Corpo: {email, motivo?}. Risponde sempre 202 (nessuna enumerazione degli account). */
    #[Route('/api/auth/recupero', name: 'api_recupero_richiesta', methods: ['POST'])]
    public function richiesta(Request $request): Response
    {
        $this->limita($request);
        $body = $this->body($request);
        $this->procedure->richiediRecupero((string) ($body['email'] ?? ''), $this->str($body, 'motivo', 500));

        return new JsonResponse(['messaggio' => 'Se l\'indirizzo corrisponde a un account con la verifica in due passaggi, riceverai un\'email per confermare la richiesta.'], Response::HTTP_ACCEPTED);
    }

    /** PUBBLICO. Corpo: {token}. */
    #[Route('/api/auth/recupero/conferma', name: 'api_recupero_conferma', methods: ['POST'])]
    public function conferma(Request $request): JsonResponse
    {
        $this->limita($request);
        $this->procedure->confermaRecupero((string) ($this->body($request)['token'] ?? ''));

        return $this->json(['messaggio' => 'Richiesta confermata: il supporto ti contatterà per verificare la tua identità. Non ti chiederà mai password o codici.']);
    }

    /** PUBBLICO. Corpo: {token} dal link dell'avviso delle 72 ore. */
    #[Route('/api/procedure/annulla', name: 'api_procedura_annulla_link', methods: ['POST'])]
    public function annullaDaLink(Request $request): JsonResponse
    {
        $this->limita($request);
        $this->procedure->annullaDaLink((string) ($this->body($request)['token'] ?? ''));

        return $this->json(['messaggio' => 'Procedura annullata. Il supporto è stato avvisato.']);
    }

    /** ?stato=identita_da_verificare|in_attesa|... (default: aperte). */
    #[Route('/api/admin/procedure', name: 'api_admin_procedure', methods: ['GET'])]
    public function elenco(Request $request): JsonResponse
    {
        $this->platform->require(PlatformAccess::SUPPORTO);
        $stato = (string) $request->query->get('stato', '');
        $qb = $this->em->createQueryBuilder()->select('p')->from(ProceduraAccesso::class, 'p')->orderBy('p.createdAt', 'DESC')->setMaxResults(200);
        '' === $stato
            ? $qb->where('p.stato IN (:aperti)')->setParameter('aperti', [ProceduraAccesso::EMAIL_DA_CONFERMARE, ProceduraAccesso::IDENTITA_DA_VERIFICARE, ProceduraAccesso::IN_ATTESA])
            : $qb->where('p.stato = :s')->setParameter('s', $stato);

        return $this->json(array_map($this->vista(...), $qb->getQuery()->getResult()));
    }

    /** Corpo: {esito: "verificata"|"respinta", documento, come, nota?}. */
    #[Route('/api/admin/procedure/{id}/identita', name: 'api_admin_procedura_identita', methods: ['POST'])]
    public function identita(string $id, Request $request): JsonResponse
    {
        $supporto = $this->platform->require(PlatformAccess::SUPPORTO);
        $p = $this->load($id);
        $body = $this->body($request);
        $this->procedure->verificaIdentita($p, $supporto, 'verificata' === ($body['esito'] ?? ''), $this->str($body, 'documento', 120), $this->str($body, 'come', 120), $this->str($body, 'nota', 500));

        return $this->json($this->vista($p));
    }

    #[Route('/api/admin/procedure/{id}/annulla', name: 'api_admin_procedura_annulla', methods: ['POST'])]
    public function annulla(string $id, Request $request): JsonResponse
    {
        $supporto = $this->platform->require(PlatformAccess::SUPPORTO);
        $p = $this->load($id);
        $this->procedure->annullaDalSupporto($p, $supporto, $this->str($this->body($request), 'nota', 500));

        return $this->json($this->vista($p));
    }

    /** Famiglia senza proprietario. Corpo: {membershipId, documento, come, nota?}. */
    #[Route('/api/admin/tenants/{id}/nuovo-proprietario', name: 'api_admin_nuovo_proprietario', methods: ['POST'])]
    public function nuovoProprietario(string $id, Request $request): JsonResponse
    {
        $supporto = $this->platform->require(PlatformAccess::SUPPORTO);
        $tenant = PerId::trova($this->em, Tenant::class, $id);
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('Famiglia non trovata.');
        }
        $body = $this->body($request);
        $mid = $this->str($body, 'membershipId', 36);
        $membership = PerId::trova($this->em, Membership::class, $mid);
        if (!$membership instanceof Membership) {
            throw new NotFoundHttpException('Membro non trovato.');
        }
        $p = $this->procedure->avviaNuovoProprietario($tenant, $membership, $supporto, $this->str($body, 'documento', 120), $this->str($body, 'come', 120), $this->str($body, 'nota', 500));

        return $this->json($this->vista($p), Response::HTTP_CREATED);
    }

    private function load(string $id): ProceduraAccesso
    {
        $p = PerId::trova($this->em, ProceduraAccesso::class, $id);

        return $p instanceof ProceduraAccesso ? $p : throw new NotFoundHttpException('Procedura non trovata.');
    }

    /** @return array<string, mixed> */
    private function vista(ProceduraAccesso $p): array
    {
        return [
            'id' => (string) $p->getId(),
            'tipo' => $p->getTipo(),
            'stato' => $p->getStato(),
            'utente' => ['id' => (string) $p->getUser()->getId(), 'nome' => $p->getUser()->getFullName(), 'email' => $p->getUser()->getEmail()],
            'famiglia' => null !== $p->getTenant() ? ['id' => (string) $p->getTenant()->getId(), 'nome' => $p->getTenant()->getName()] : null,
            'motivo' => $p->getMotivo(),
            'verificaDocumento' => $p->getVerificaDocumento(),
            'verificaCome' => $p->getVerificaCome(),
            'verificataDa' => $p->getVerificataDa()?->getFullName(),
            'nota' => $p->getNota(),
            'eseguibileIl' => $p->getEseguibileIl()?->format(\DATE_ATOM),
            'chiusaIl' => $p->getChiusaIl()?->format(\DATE_ATOM),
            'createdAt' => $p->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
