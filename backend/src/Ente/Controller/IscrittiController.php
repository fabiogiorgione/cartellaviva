<?php

namespace App\Ente\Controller;

use App\Account\EmailVerificata;
use App\Ente\CollegamentoService;
use App\Ente\EnteAccess;
use App\Ente\EnteJson;
use App\Ente\IscrittiService;
use App\Ente\Entity\Gruppo;
use App\Ente\Entity\Iscritto;
use App\Ente\Entity\Referente;
use App\Shared\Doctrine\PerId;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Iscritti e referenti dell'ente, import da CSV, inviti a collegarsi.
 * Ogni operazione rispetta l'ambito per gruppo (EnteAccess).
 */
final class IscrittiController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly EnteAccess $access,
        private readonly EntityManagerInterface $em,
        private readonly IscrittiService $iscritti,
        private readonly CollegamentoService $collegamenti,
    ) {
    }

    /**
     * Filtri: gruppo=<id>, q=<testo>, filtro=senza_email|da_invitare|invitati|non_collegati.
     * Restituisce anche i totali sull'insieme filtrato per gruppo.
     */
    #[Route('/api/enti/{tenantId}/iscritti', name: 'api_ente_iscritti', methods: ['GET'])]
    public function elenco(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:read');
        $gruppo = $request->query->has('gruppo') ? $this->gruppo($ente, (string) $request->query->get('gruppo')) : null;
        $esito = $this->iscritti->elenco($ente, $gruppo, (string) $request->query->get('q', ''), (string) $request->query->get('filtro', ''));

        return $this->json(['iscritti' => array_map(EnteJson::iscritto(...), $esito['iscritti']), 'totali' => $esito['totali']]);
    }

    #[Route('/api/enti/{tenantId}/iscritti/{id}', name: 'api_ente_iscritto', methods: ['GET'])]
    public function dettaglio(string $tenantId, string $id): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:read');
        $iscritto = $this->iscritto($ente, $id);
        $this->access->require('iscritto:read', $iscritto);

        return $this->json(EnteJson::iscritto($iscritto));
    }

    /**
     * Corpo: {nome, cognome, dataNascita?, gruppiIds: [...], referenti?: [{nome, cognome, email?, relazione?}]}.
     */
    #[Route('/api/enti/{tenantId}/iscritti', name: 'api_ente_iscritto_crea', methods: ['POST'])]
    public function crea(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:manage');
        $body = $this->body($request);
        $referenti = array_map($this->datiReferente(...), array_values(array_filter(\is_array($body['referenti'] ?? null) ? $body['referenti'] : [], 'is_array')));
        $iscritto = $this->iscritti->crea($ente, $this->obbligatorio($body, 'nome'), $this->obbligatorio($body, 'cognome'), $this->date($body, 'dataNascita'), $this->gruppiDa($ente, $body), $referenti);

        return $this->json(EnteJson::iscritto($iscritto), Response::HTTP_CREATED);
    }

    #[Route('/api/enti/{tenantId}/iscritti/{id}', name: 'api_ente_iscritto_aggiorna', methods: ['PUT'])]
    public function aggiorna(string $tenantId, string $id, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:manage');
        $iscritto = $this->iscritto($ente, $id);
        $body = $this->body($request);
        $gruppi = \array_key_exists('gruppiIds', $body) ? $this->gruppiDa($ente, $body) : null;
        $this->iscritti->aggiorna($iscritto, $this->obbligatorio($body, 'nome'), $this->obbligatorio($body, 'cognome'), $this->date($body, 'dataNascita'), $gruppi);

        return $this->json(EnteJson::iscritto($iscritto));
    }

    #[Route('/api/enti/{tenantId}/iscritti/{id}', name: 'api_ente_iscritto_elimina', methods: ['DELETE'])]
    public function elimina(string $tenantId, string $id): Response
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:manage');
        $this->iscritti->elimina($this->iscritto($ente, $id));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/enti/{tenantId}/iscritti/{id}/referenti', name: 'api_ente_referente_crea', methods: ['POST'])]
    public function creaReferente(string $tenantId, string $id, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:manage');
        $iscritto = $this->iscritto($ente, $id);
        $referente = $this->iscritti->aggiungiReferente($iscritto, $this->datiReferente($this->body($request)));

        return $this->json(EnteJson::referente($referente), Response::HTTP_CREATED);
    }

    #[Route('/api/enti/{tenantId}/referenti/{id}', name: 'api_ente_referente_aggiorna', methods: ['PUT'])]
    public function aggiornaReferente(string $tenantId, string $id, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:manage');
        $referente = $this->referente($ente, $id);
        $this->iscritti->aggiornaReferente($referente, $this->datiReferente($this->body($request)));

        return $this->json(EnteJson::referente($referente));
    }

    #[Route('/api/enti/{tenantId}/referenti/{id}', name: 'api_ente_referente_elimina', methods: ['DELETE'])]
    public function eliminaReferente(string $tenantId, string $id): Response
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:manage');
        $this->iscritti->eliminaReferente($this->referente($ente, $id));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Corpo: {"csv": "...", "applica": false}. Con applica=false restituisce solo
     * l'anteprima; con true importa le righe valide (gli inviti NON partono).
     */
    #[Route('/api/enti/{tenantId}/iscritti/import', name: 'api_ente_iscritti_import', methods: ['POST'], priority: 10)]
    public function importa(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:manage');
        $body = $this->body($request);
        $csv = \is_string($body['csv'] ?? null) ? $body['csv'] : throw new UnprocessableEntityHttpException('csv: contenuto del file obbligatorio.');
        $esito = $this->iscritti->importa($ente, $csv, true === ($body['applica'] ?? false));

        return $this->json($esito);
    }

    /**
     * Invio degli inviti a collegarsi: {"referenteIds": [...]} oppure {"gruppoId": "..."}
     * (tutti i referenti invitabili del gruppo). Solo da ente verificato.
     */
    #[Route('/api/enti/{tenantId}/inviti', name: 'api_ente_inviti', methods: ['POST'])]
    public function inviti(string $tenantId, Request $request): JsonResponse
    {
        $ente = $this->access->requireEnte($tenantId, 'iscritto:invite');
        $this->access->assertVerificato($ente);
        EmailVerificata::richiedi($this->access->currentUser());
        $body = $this->body($request);
        $referenti = [];
        if (null !== ($gid = $this->str($body, 'gruppoId', 36))) {
            $referenti = $this->iscritti->referentiDelGruppo($this->gruppo($ente, $gid));
        } else {
            foreach (\is_array($body['referenteIds'] ?? null) ? $body['referenteIds'] : [] as $rid) {
                if (\is_string($rid)) {
                    $referenti[] = $this->referente($ente, $rid);
                }
            }
        }
        if ([] === $referenti) {
            throw new UnprocessableEntityHttpException('Indica i referenti o il gruppo da invitare.');
        }

        return $this->json($this->collegamenti->invita($ente, $referenti, $this->access->currentUser()));
    }

    // ---- Helpers ----------------------------------------------------------

    /** @return array{nome: string, cognome: string, email: ?string, relazione: ?string} */
    private function datiReferente(array $r): array
    {
        return ['nome' => $this->obbligatorio($r, 'nome'), 'cognome' => $this->obbligatorio($r, 'cognome'), 'email' => $this->email($r), 'relazione' => $this->str($r, 'relazione', 50)];
    }

    private function email(array $data): ?string
    {
        $email = $this->str($data, 'email', 180);
        if (null === $email) {
            return null;
        }
        $email = mb_strtolower($email);
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new UnprocessableEntityHttpException('email: indirizzo non valido.');
        }

        return $email;
    }

    private function obbligatorio(array $data, string $key): string
    {
        return $this->str($data, $key, 100) ?? throw new UnprocessableEntityHttpException($key.': obbligatorio.');
    }

    /** @return list<Gruppo> */
    private function gruppiDa(Tenant $ente, array $body): array
    {
        $gruppi = [];
        foreach (\is_array($body['gruppiIds'] ?? null) ? $body['gruppiIds'] : [] as $gid) {
            if (\is_string($gid)) {
                $gruppi[] = $this->gruppo($ente, $gid);
            }
        }

        return $gruppi;
    }

    private function gruppo(Tenant $ente, string $id): Gruppo
    {
        $g = PerId::trova($this->em, Gruppo::class, $id);
        if (!$g instanceof Gruppo || !$g->getTenant()->getId()->equals($ente->getId()) || !$this->access->puoVedereGruppo($g)) {
            throw new NotFoundHttpException('Gruppo non trovato.');
        }

        return $g;
    }

    private function iscritto(Tenant $ente, string $id): Iscritto
    {
        $i = PerId::trova($this->em, Iscritto::class, $id);
        if (!$i instanceof Iscritto || !$i->getTenant()->getId()->equals($ente->getId())) {
            throw new NotFoundHttpException('Iscritto non trovato.');
        }
        $this->access->assertIscritto($i);

        return $i;
    }

    private function referente(Tenant $ente, string $id): Referente
    {
        $r = PerId::trova($this->em, Referente::class, $id);
        if (!$r instanceof Referente || !$r->getTenant()->getId()->equals($ente->getId())) {
            throw new NotFoundHttpException('Referente non trovato.');
        }
        $this->access->assertIscritto($r->getIscritto());

        return $r;
    }
}
