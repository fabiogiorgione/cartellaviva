<?php

namespace App\Ente\Controller;

use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\FileCifratoService;
use App\Ente\Firma\FirmaCodiceService;
use App\Ente\RichiesteService;
use App\Ente\RisposteService;
use App\Shared\Doctrine\PerId;
use App\Shared\Http\JsonBodyTrait;
use App\Shared\Http\RispostaFile;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Richieste degli enti come le vede la famiglia: elenco, dettaglio (segna
 * "vista"), PDF dell'ente, risposta, "non posso firmare".
 */
final class RichiesteFamigliaController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly TenantAccess $access,
        private readonly EntityManagerInterface $em,
        private readonly RisposteService $risposte,
        private readonly RichiesteService $richieste,
        private readonly FileCifratoService $files,
        private readonly FirmaCodiceService $firme,
    ) {
    }

    /** ?stato=da_fare|in_verifica|concluse (default tutte). */
    #[Route('/api/tenants/{tenantId}/richieste', name: 'api_famiglia_richieste', methods: ['GET'])]
    public function elenco(string $tenantId, Request $request): JsonResponse
    {
        $famiglia = $this->access->requireTenant($tenantId, 'richiesta:read');
        $tutte = $this->em->createQuery('SELECT d, r FROM '.RichiestaDestinatario::class.' d JOIN d.richiesta r WHERE d.famiglia = :f ORDER BY r.createdAt DESC')
            ->setParameter('f', $famiglia->getId(), 'uuid')->getResult();
        $filtro = (string) $request->query->get('stato', '');
        $righe = array_filter($tutte, static fn (RichiestaDestinatario $d) => match ($filtro) {
            'da_fare' => $d->getStato()->isDaFare() && !$d->getRichiesta()->isAnnullata(),
            'in_verifica' => 'da_verificare' === $d->getStato()->value,
            'concluse' => $d->getStato()->isConclusa(),
            default => true,
        });

        return $this->json(array_values(array_map(fn ($d) => $this->vista($d, false), $righe)));
    }

    #[Route('/api/tenants/{tenantId}/richieste/{id}', name: 'api_famiglia_richiesta', methods: ['GET'])]
    public function dettaglio(string $tenantId, string $id): JsonResponse
    {
        $d = $this->destinatario($tenantId, $id);
        $this->risposte->segnaVista($d, $this->access->currentUser());

        return $this->json($this->vista($d, true));
    }

    #[Route('/api/tenants/{tenantId}/richieste/{id}/allegato', name: 'api_famiglia_richiesta_allegato', methods: ['GET'])]
    public function allegato(string $tenantId, string $id): Response
    {
        $d = $this->destinatario($tenantId, $id);
        $file = $d->getRichiesta()->getAllegato() ?? throw new NotFoundHttpException('Nessun allegato.');
        $this->richieste->logEntrambi('richiesta.allegato_scaricato', $d, $this->access->currentUser());
        return RispostaFile::allegato($this->files->read($file), $file->getMimeType(), $file->getNomeFile());
    }

    /**
     * Multipart (file + firmatari[]) oppure JSON:
     *   {"firmatari": [...], "documentoId": "..."}  (invio dall'archivio)
     *   {"firmatari": [...]}                       (presa visione)
     */
    #[Route('/api/tenants/{tenantId}/richieste/{id}/risposta', name: 'api_famiglia_richiesta_risposta', methods: ['POST'])]
    public function rispondi(string $tenantId, string $id, Request $request): JsonResponse
    {
        $d = $this->destinatario($tenantId, $id);
        $file = $request->files->get('file');
        if ($file instanceof UploadedFile) {
            if (!$file->isValid()) {
                throw new UnprocessableEntityHttpException('file: caricamento non riuscito.');
            }
            $form = $request->request->all();
            $firmatari = $form['firmatari'] ?? [];
            $this->risposte->rispondi($d, $this->access->currentUser(), \is_array($firmatari) ? array_map('strval', $firmatari) : [], (string) file_get_contents($file->getPathname()), $file->getClientOriginalName(), null, $this->date($form, 'validoFino'));
        } else {
            $body = $this->body($request);
            $firmatari = \is_array($body['firmatari'] ?? null) ? array_map('strval', $body['firmatari']) : [];
            $this->risposte->rispondi($d, $this->access->currentUser(), $firmatari, null, null, $this->str($body, 'documentoId', 36), $this->date($body, 'validoFino'));
        }

        return $this->json($this->vista($d, true));
    }

    /** Firma con codice: invia il codice all'email del referente che l'utente rappresenta. */
    #[Route('/api/tenants/{tenantId}/richieste/{id}/firma/codice', name: 'api_famiglia_firma_codice', methods: ['POST'])]
    public function codiceFirma(string $tenantId, string $id): JsonResponse
    {
        $d = $this->destinatario($tenantId, $id);
        $this->access->require('richiesta:respond', $d);
        $user = $this->access->currentUser();
        $referente = $this->firme->referenteDi($d, $user) ?? throw new ConflictHttpException('Non risulti tra i firmatari: firma con l\'account dell\'email a cui l\'ente ha mandato l\'invito.');
        $firma = $this->firme->inviaCodice($d, $referente, $user);

        return $this->json(['firmatario' => $referente->getNomeCompleto(), 'documentoSha256' => $firma->getDocumentoSha256(), 'dichiarazione' => $firma->getDichiarazione()]);
    }

    /** Corpo: {"codice": "123456", "accetto": true}. */
    #[Route('/api/tenants/{tenantId}/richieste/{id}/firma', name: 'api_famiglia_firma', methods: ['POST'])]
    public function firma(string $tenantId, string $id, Request $request): JsonResponse
    {
        $d = $this->destinatario($tenantId, $id);
        $this->access->require('richiesta:respond', $d);
        $user = $this->access->currentUser();
        $referente = $this->firme->referenteDi($d, $user) ?? throw new ConflictHttpException('Non risulti tra i firmatari di questa richiesta.');
        $body = $this->body($request);
        $this->firme->firma($d, $referente, (string) ($body['codice'] ?? ''), true === ($body['accetto'] ?? false), $user, $request->getClientIp(), $request->headers->get('User-Agent'));

        return $this->json($this->vista($d, true));
    }

    /** Corpo: {"messaggio": "..."} — "non posso firmare" / "non autorizzo". */
    #[Route('/api/tenants/{tenantId}/richieste/{id}/rifiuta', name: 'api_famiglia_richiesta_rifiuta', methods: ['POST'])]
    public function rifiuta(string $tenantId, string $id, Request $request): JsonResponse
    {
        $d = $this->destinatario($tenantId, $id);
        $this->risposte->rifiuta($d, $this->access->currentUser(), (string) ($this->body($request)['messaggio'] ?? ''));

        return $this->json($this->vista($d, true));
    }

    private function destinatario(string $tenantId, string $id): RichiestaDestinatario
    {
        $famiglia = $this->access->requireTenant($tenantId, 'richiesta:read');
        $d = PerId::trova($this->em, RichiestaDestinatario::class, $id);
        if (!$d instanceof RichiestaDestinatario || null === $d->getFamiglia() || !$d->getFamiglia()->getId()->equals($famiglia->getId())) {
            throw new NotFoundHttpException('Richiesta non trovata.');
        }
        $this->access->require('richiesta:read', $d);

        return $d;
    }

    /** @return array<string, mixed> solo dati di questa famiglia */
    private function vista(RichiestaDestinatario $d, bool $dettaglio): array
    {
        $r = $d->getRichiesta();
        // Firme con codice già apposte (anche prima che firmino tutti) e il referente dell'utente.
        $firmatiCodice = array_map(static fn ($f) => (string) $f->getReferente()->getId(), $r->isFirmaConCodice() ? $this->firme->firmate($d) : []);
        $mio = $r->isFirmaConCodice() ? $this->firme->referenteDi($d, $this->access->currentUser()) : null;
        $base = [
            'id' => (string) $d->getId(),
            'ente' => ['id' => (string) $r->getTenant()->getId(), 'nome' => $r->getTenant()->getName()],
            'tipo' => $r->getTipo()->value,
            'titolo' => $r->getTitolo(),
            'iscritto' => $d->getIscritto()->getNomeCompleto(),
            'entroIl' => $r->getEntroIl()?->format('Y-m-d'),
            'stato' => $r->isAnnullata() ? 'annullata' : $d->getStato()->value,
            'scaduta' => $d->isScaduta(),
            'firmatari' => $r->getFirmatari(),
            'referenti' => array_values(array_map(static fn ($ref) => [
                'id' => (string) $ref->getId(),
                'nome' => $ref->getNomeCompleto(),
                'firmato' => \in_array((string) $ref->getId(), $d->getFirmatari(), true) || \in_array((string) $ref->getId(), $firmatiCodice, true),
                'tuo' => $ref === $mio,
            ], $d->getReferenti()->toArray())),
            'motivoEnte' => $d->getMotivoEnte(),
            'modalitaFirma' => $r->getModalitaFirma(),
            'chiediValidita' => $r->isChiediValidita(),
            'validoFino' => $d->getValidoFino()?->format('Y-m-d'),
            'createdAt' => $r->getCreatedAt()->format(\DATE_ATOM),
        ];
        if (!$dettaglio) {
            return $base;
        }

        return $base + [
            'istruzioni' => $r->getIstruzioni(),
            'allegato' => null !== $r->getAllegato() ? ['nome' => $r->getAllegato()->getNomeFile(), 'dimensione' => $r->getAllegato()->getDimensione()] : null,
            'messaggioFamiglia' => $d->getMessaggioFamiglia(),
            'rispostaIl' => $d->getRispostaIl()?->format(\DATE_ATOM),
            'rispostaDa' => $d->getRispostaDa()?->getFullName(),
            'documentoId' => null !== $d->getDocumentoFamiglia() ? (string) $d->getDocumentoFamiglia()->getId() : null,
        ];
    }
}
