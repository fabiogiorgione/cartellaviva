<?php

namespace App\Portale\Controller;

use App\Account\Entity\User;
use App\Portale\Csv;
use App\Portale\Entity\Idea;
use App\Portale\IdeeService;
use App\Portale\PlatformAccess;
use App\Shared\Doctrine\PerId;
use App\Shared\Http\JsonBodyTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Portale delle idee.
 * - Utenti con account: inviano e vedono solo le proprie (/api/idee).
 * - Portale (/api/admin/idee): VEDERE legge; SUPPORTO cambia stato, risponde,
 *   unisce i duplicati e vede il contatto se l'autore l'ha concesso; ADMIN
 *   elimina ed esporta.
 */
final class IdeeController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly IdeeService $idee,
        private readonly PlatformAccess $platform,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Corpo: {tipo, area?, testo, contattabile?, tenantId?, piattaforma?, versioneApp?}. */
    #[Route('/api/idee', name: 'api_idee_invia', methods: ['POST'])]
    public function invia(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $idea = $this->idee->invia($this->utente(), [
            'tipo' => $this->str($body, 'tipo', 20),
            'area' => $this->str($body, 'area', 20),
            'testo' => isset($body['testo']) && \is_string($body['testo']) ? $body['testo'] : null,
            'contattabile' => false !== ($body['contattabile'] ?? true),
            'tenantId' => $this->str($body, 'tenantId', 36),
            'piattaforma' => $this->str($body, 'piattaforma', 20),
            'versioneApp' => $this->str($body, 'versioneApp', 20),
        ]);

        return $this->json($this->vistaAutore($idea), Response::HTTP_CREATED);
    }

    #[Route('/api/idee', name: 'api_idee_mie', methods: ['GET'])]
    public function mie(): JsonResponse
    {
        $idee = $this->em->getRepository(Idea::class)->findBy(['autore' => $this->utente()], ['createdAt' => 'DESC', 'id' => 'DESC']);

        return $this->json(array_map($this->vistaAutore(...), $idee));
    }

    /** ?stato=&tipo=&area=&spazio=&q= ; per default nasconde i duplicati (?duplicati=1 per vederli). */
    #[Route('/api/admin/idee', name: 'api_admin_idee', methods: ['GET'])]
    public function elenco(Request $request): JsonResponse
    {
        $chi = $this->platform->require(PlatformAccess::VEDERE);
        $qb = $this->em->createQueryBuilder()->select('i')->from(Idea::class, 'i')->orderBy('i.createdAt', 'DESC')->addOrderBy('i.id', 'DESC')->setMaxResults(500);
        if (!$request->query->getBoolean('duplicati')) {
            $qb->andWhere('i.duplicatoDi IS NULL');
        }
        foreach (['stato' => 'stato', 'tipo' => 'tipo', 'area' => 'area', 'spazio' => 'tipoSpazio'] as $param => $campo) {
            $v = trim((string) $request->query->get($param, ''));
            if ('' !== $v) {
                $qb->andWhere("i.$campo = :$param")->setParameter($param, $v);
            }
        }
        $q = trim((string) $request->query->get('q', ''));
        if ('' !== $q) {
            $qb->andWhere('LOWER(i.testo) LIKE :q')->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_\\').'%');
        }
        $conteggi = $this->idee->conteggioDuplicati();
        $supporto = $this->platform->has($chi, PlatformAccess::SUPPORTO);

        return $this->json(array_map(fn (Idea $i) => $this->vistaPortale($i, $conteggi, $supporto), $qb->getQuery()->getResult()));
    }

    #[Route('/api/admin/idee/{id}', name: 'api_admin_idea', methods: ['GET'])]
    public function dettaglio(string $id): JsonResponse
    {
        $chi = $this->platform->require(PlatformAccess::VEDERE);
        $idea = $this->load($id);
        $supporto = $this->platform->has($chi, PlatformAccess::SUPPORTO);
        $vista = $this->vistaPortale($idea, $this->idee->conteggioDuplicati(), $supporto);
        $vista['duplicati'] = array_map(fn (Idea $d) => $this->vistaPortale($d, [], $supporto), $this->idee->duplicati($idea));

        return $this->json($vista);
    }

    /** Corpo: {stato?, rispostaPubblica?, notaInterna?} (null o "" svuota risposta e nota). */
    #[Route('/api/admin/idee/{id}', name: 'api_admin_idea_aggiorna', methods: ['PATCH'])]
    public function aggiorna(string $id, Request $request): JsonResponse
    {
        $supporto = $this->platform->require(PlatformAccess::SUPPORTO);
        $idea = $this->load($id);
        $body = $this->body($request);
        $modifiche = [];
        if (\array_key_exists('stato', $body)) {
            $modifiche['stato'] = $this->str($body, 'stato', 20);
        }
        foreach (['rispostaPubblica', 'notaInterna'] as $k) {
            if (\array_key_exists($k, $body)) {
                $modifiche[$k] = $this->str($body, $k, 2000);
            }
        }
        $this->idee->aggiorna($idea, $supporto, $modifiche);

        return $this->json($this->vistaPortale($idea, $this->idee->conteggioDuplicati(), true));
    }

    /** Corpo: {principaleId} per unire; {principaleId: null} per separare. */
    #[Route('/api/admin/idee/{id}/unisci', name: 'api_admin_idea_unisci', methods: ['POST'])]
    public function unisci(string $id, Request $request): JsonResponse
    {
        $supporto = $this->platform->require(PlatformAccess::SUPPORTO);
        $idea = $this->load($id);
        $principaleId = $this->str($this->body($request), 'principaleId', 36);
        null === $principaleId ? $this->idee->separa($idea, $supporto) : $this->idee->unisci($idea, $this->load($principaleId), $supporto);

        return $this->json($this->vistaPortale($idea, $this->idee->conteggioDuplicati(), true));
    }

    #[Route('/api/admin/idee/{id}', name: 'api_admin_idea_elimina', methods: ['DELETE'])]
    public function elimina(string $id): Response
    {
        $this->idee->elimina($this->load($id), $this->platform->require(PlatformAccess::ADMIN));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** CSV (";", UTF-8 con BOM) delle idee principali, con il numero di richieste. Senza contatti. */
    #[Route('/api/admin/idee-export', name: 'api_admin_idee_export', methods: ['GET'])]
    public function esporta(): Response
    {
        $this->platform->require(PlatformAccess::ADMIN);
        $conteggi = $this->idee->conteggioDuplicati();
        $righe = [['data', 'tipo', 'area', 'spazio', 'piattaforma', 'versione', 'stato', 'richieste', 'testo', 'risposta']];
        foreach ($this->em->getRepository(Idea::class)->findBy(['duplicatoDi' => null], ['createdAt' => 'ASC', 'id' => 'ASC']) as $i) {
            $righe[] = [
                $i->getCreatedAt()->format('Y-m-d'), $i->getTipo(), (string) $i->getArea(), (string) $i->getTipoSpazio(),
                (string) $i->getPiattaforma(), (string) $i->getVersioneApp(), $i->getStato(),
                (string) (1 + ($conteggi[(string) $i->getId()] ?? 0)), $i->getTesto(), (string) $i->getRispostaPubblica(),
            ];
        }

        return Csv::risposta('idee', $righe);
    }

    private function utente(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new AccessDeniedHttpException('Serve un account.');
    }

    private function load(string $id): Idea
    {
        $idea = PerId::trova($this->em, Idea::class, $id);

        return $idea instanceof Idea ? $idea : throw new NotFoundHttpException('Idea non trovata.');
    }

    /**
     * Per l'autore stato e risposta sono quelli della principale, se la sua
     * idea è stata unita a un'altra (di cui non vede il testo).
     *
     * @return array<string, mixed>
     */
    private function vistaAutore(Idea $i): array
    {
        $p = $i->getPrincipale();

        return [
            'id' => (string) $i->getId(),
            'tipo' => $i->getTipo(),
            'area' => $i->getArea(),
            'testo' => $i->getTesto(),
            'contattabile' => $i->isContattabile(),
            'stato' => $p->getStato(),
            'rispostaPubblica' => $p->getRispostaPubblica(),
            'unita' => null !== $i->getDuplicatoDi(),
            'createdAt' => $i->getCreatedAt()->format(\DATE_ATOM),
            'aggiornataIl' => max($i->getAggiornataIl(), $p->getAggiornataIl())->format(\DATE_ATOM),
        ];
    }

    /**
     * @param array<string, int> $conteggi
     *
     * @return array<string, mixed>
     */
    private function vistaPortale(Idea $i, array $conteggi, bool $supporto): array
    {
        $autore = $i->getAutore();

        return [
            'id' => (string) $i->getId(),
            'tipo' => $i->getTipo(),
            'area' => $i->getArea(),
            'testo' => $i->getTesto(),
            'stato' => $i->getStato(),
            'rispostaPubblica' => $i->getRispostaPubblica(),
            'notaInterna' => $i->getNotaInterna(),
            'tipoSpazio' => $i->getTipoSpazio(),
            'piattaforma' => $i->getPiattaforma(),
            'versioneApp' => $i->getVersioneApp(),
            'richieste' => 1 + ($conteggi[(string) $i->getId()] ?? 0),
            'duplicatoDi' => null !== $i->getDuplicatoDi() ? (string) $i->getDuplicatoDi()->getId() : null,
            'anonima' => null === $autore,
            // Il contatto solo al supporto e solo se l'autore l'ha concesso.
            'contatto' => $supporto && null !== $autore && $i->isContattabile() ? ['nome' => $autore->getFullName(), 'email' => $autore->getEmail()] : null,
            'createdAt' => $i->getCreatedAt()->format(\DATE_ATOM),
            'aggiornataIl' => $i->getAggiornataIl()->format(\DATE_ATOM),
        ];
    }
}
