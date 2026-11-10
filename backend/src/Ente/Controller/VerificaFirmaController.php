<?php

namespace App\Ente\Controller;

use App\Ente\Firma\FirmaCodiceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Verifica PUBBLICA di un'attestazione di firma con codice: chi ha il PDF
 * inserisce il codice stampato e vede le stesse evidenze registrate. Solo i
 * dati già presenti sull'attestazione (email mascherata).
 */
final class VerificaFirmaController extends AbstractController
{
    public function __construct(
        private readonly FirmaCodiceService $firme,
        #[Autowire(service: 'limiter.public_auth')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/verifica-firma/{codice}', name: 'api_verifica_firma', methods: ['GET'], requirements: ['codice' => '[A-Za-z0-9]{8,16}'])]
    public function __invoke(string $codice, Request $request): JsonResponse
    {
        if (!$this->limiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Troppi tentativi, riprova più tardi.');
        }
        $f = $this->firme->verifica($codice) ?? throw new NotFoundHttpException('Nessuna firma con questo codice.');
        $r = $f->getDestinatario()->getRichiesta();
        [$nome, $dominio] = array_pad(explode('@', $f->getEmailFirmatario(), 2), 2, '');

        return $this->json([
            'valida' => true,
            'ente' => $r->getTenant()->getName(),
            'documento' => $r->getTitolo(),
            'firmatario' => $f->getNomeFirmatario(),
            'email' => mb_substr($nome, 0, 1).'•••@'.$dominio,
            'firmataIl' => $f->getFirmataIl()?->format(\DATE_ATOM),
            'metodo' => null !== $f->getUser() ? 'account + codice email' : 'link personale + codice email',
            'documentoSha256' => $f->getDocumentoSha256(),
            'documentoFirmatoSha256' => $f->getDocumentoFirmatoSha256(),
        ]);
    }
}
