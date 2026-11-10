<?php

namespace App\Ente\Controller;

use App\Ente\Entity\RichiestaDestinatario;
use App\Ente\FileCifratoService;
use App\Ente\Firma\FirmaCodiceService;
use App\Ente\LinkRispostaService;
use App\Shared\Http\JsonBodyTrait;
use App\Shared\Http\RispostaFile;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Risposta senza account (/api/link/{token}…), PUBBLICA: il token del link e
 * la sessione ottenuta con il codice email (header X-Link-Sessione) fanno da
 * credenziali. Solo "collega" richiede anche l'accesso con l'account.
 */
final class LinkRispostaController extends AbstractController
{
    use JsonBodyTrait;

    public function __construct(
        private readonly LinkRispostaService $link,
        private readonly FileCifratoService $files,
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly FirmaCodiceService $firme,
        #[Autowire(service: 'limiter.public_auth')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    /** Prima del codice: solo ente ed email mascherata, nessun contenuto. */
    #[Route('/api/link/{token}', name: 'api_link_apri', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function apri(string $token, Request $request): JsonResponse
    {
        $d = $this->d($token, $request);

        return $this->json(['ente' => $d->getRichiesta()->getTenant()->getName(), 'email' => LinkRispostaService::emailMascherata((string) $d->getReferenteLink()?->getEmail())]);
    }

    #[Route('/api/link/{token}/codice', name: 'api_link_codice', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function codice(string $token, Request $request): Response
    {
        $this->link->inviaCodice($this->d($token, $request));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** Corpo {codice} -> {sessione} da mandare nell'header X-Link-Sessione. */
    #[Route('/api/link/{token}/verifica', name: 'api_link_verifica', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function verifica(string $token, Request $request): JsonResponse
    {
        $d = $this->d($token, $request);

        return $this->json(['sessione' => $this->link->verificaCodice($d, (string) ($this->body($request)['codice'] ?? ''))]);
    }

    #[Route('/api/link/{token}/richiesta', name: 'api_link_richiesta', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function richiesta(string $token, Request $request): JsonResponse
    {
        return $this->json($this->link->vista($this->conSessione($token, $request)));
    }

    #[Route('/api/link/{token}/allegato', name: 'api_link_allegato', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function allegato(string $token, Request $request): Response
    {
        $d = $this->conSessione($token, $request);
        $file = $d->getRichiesta()->getAllegato() ?? throw new NotFoundHttpException('Nessun allegato.');
        return RispostaFile::allegato($this->files->read($file), $file->getMimeType(), $file->getNomeFile());
    }

    /** Multipart (file, validoFino?) oppure JSON vuoto per la presa visione. */
    #[Route('/api/link/{token}/risposta', name: 'api_link_risposta', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function risposta(string $token, Request $request): JsonResponse
    {
        $d = $this->conSessione($token, $request);
        $file = $request->files->get('file');
        $dati = $file instanceof UploadedFile ? $request->request->all() : $this->body($request);
        if ($file instanceof UploadedFile && !$file->isValid()) {
            throw new UnprocessableEntityHttpException('file: caricamento non riuscito.');
        }
        $this->link->rispondi($d, $file instanceof UploadedFile ? (string) file_get_contents($file->getPathname()) : null, $file instanceof UploadedFile ? $file->getClientOriginalName() : null, $this->date($dati, 'validoFino'));

        return $this->json($this->link->vista($d));
    }

    /** Firma con codice dal link: il codice va di nuovo all'email del referente. */
    #[Route('/api/link/{token}/firma/codice', name: 'api_link_firma_codice', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function codiceFirma(string $token, Request $request): JsonResponse
    {
        $d = $this->conSessione($token, $request);
        $firma = $this->firme->inviaCodice($d, $d->getReferenteLink() ?? throw new NotFoundHttpException('Link non valido.'), null);

        return $this->json(['documentoSha256' => $firma->getDocumentoSha256(), 'dichiarazione' => $firma->getDichiarazione()]);
    }

    /** Corpo: {"codice": "123456", "accetto": true}. */
    #[Route('/api/link/{token}/firma', name: 'api_link_firma', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function firma(string $token, Request $request): JsonResponse
    {
        $d = $this->conSessione($token, $request);
        $body = $this->body($request);
        $this->firme->firma($d, $d->getReferenteLink() ?? throw new NotFoundHttpException('Link non valido.'), (string) ($body['codice'] ?? ''), true === ($body['accetto'] ?? false), null, $request->getClientIp(), $request->headers->get('User-Agent'));

        return $this->json($this->link->vista($d));
    }

    #[Route('/api/link/{token}/rifiuta', name: 'api_link_rifiuta', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function rifiuta(string $token, Request $request): JsonResponse
    {
        $d = $this->conSessione($token, $request);
        $this->link->rifiuta($d, (string) ($this->body($request)['messaggio'] ?? ''));

        return $this->json($this->link->vista($d));
    }

    /**
     * AUTENTICATO (stessa email del referente). Corpo: {tenantId, personaId? | nuovaPersona?}.
     * Collega il referente alla famiglia e vi copia le risposte già date dal link.
     */
    #[Route('/api/link/{token}/collega', name: 'api_link_collega', methods: ['POST'], requirements: ['token' => '[A-Za-z0-9_-]{20,100}'])]
    public function collega(string $token, Request $request): JsonResponse
    {
        $d = $this->conSessione($token, $request);
        $body = $this->body($request);
        $famiglia = $this->tenants->findByPublicId((string) ($body['tenantId'] ?? '')) ?? throw new NotFoundHttpException('Famiglia non trovata.');
        $passate = $this->link->collegaAccount($d, $this->access->currentUser(), $famiglia, $this->str($body, 'personaId', 36), \is_array($body['nuovaPersona'] ?? null) ? $body['nuovaPersona'] : null);

        return $this->json(['risposte' => $passate]);
    }

    private function d(string $token, Request $request): RichiestaDestinatario
    {
        if (!$this->limiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }

        return $this->link->apri($token);
    }

    private function conSessione(string $token, Request $request): RichiestaDestinatario
    {
        $d = $this->link->apri($token);
        $this->link->assertSessione($d, (string) $request->headers->get('X-Link-Sessione', ''));

        return $d;
    }
}
