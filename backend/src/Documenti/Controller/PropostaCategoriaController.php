<?php

namespace App\Documenti\Controller;

use App\ConnettoreAi\LavoriClassificazione;
use App\Documenti\Classificazione\PropostaCategoria;
use App\Documenti\Classificazione\TurnoOccupato;
use App\Documenti\Classificazione\TurnoProposte;
use App\Documenti\Repository\DocumentoRepository;
use App\Shared\Http\JsonBodyTrait;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Proposta di categoria con l'AI, un documento per volta (il wizard le chiede in fila):
 *   POST /api/tenants/{tenantId}/documenti/{id}/proposta-categoria   documento:write
 *   {"nuove": ["Veterinario"], "escludi": [id, ...]}   facoltativi: categorie nuove già proposte per
 *   gli altri documenti, e i documenti in revisione insieme (non fanno da esempio per le categorie)
 *
 * Risponde {"tipo": "esistente", "categoriaId", "nome"} | {"tipo": "nuova", "nome",
 * "sensibile", "icona"} | {"tipo": "nessuna", "motivo"}, sempre con "fonte"
 * ("testo" o "nome"). Non cambia il documento.
 *
 * Costa decine di secondi di CPU e un thread del server: una proposta alla volta per
 * utente, poche insieme sulla piattaforma (TurnoProposte) e un limite orario per utente.
 * Se non c'è posto risponde 429 con Retry-After di pochi secondi (il wizard riprova);
 * oltre il limite orario, 429 con il tempo che manca.
 *
 * Con il Mac della famiglia online la proposta la fa il Mac (LavoriClassificazione):
 * la prima richiesta la avvia e risponde 429 con Retry-After breve, come per un turno
 * occupato; il wizard ripete la stessa richiesta finché arriva l'esito. Se il Mac non
 * ce la fa, la richiesta successiva la fa il server. Il limite orario si conta una
 * volta per proposta, non a ogni richiesta ripetuta.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PropostaCategoriaController extends AbstractController
{
    use JsonBodyTrait;

    private const MAX_NUOVE = 30;
    private const MAX_ESCLUDI = 50;
    private const RIPROVA_TRA = 5;
    private const RIPROVA_SUL_MAC = 4;
    private const SUL_MAC = 'Il Mac della famiglia sta analizzando il documento.';

    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly DocumentoRepository $documenti,
        private readonly PropostaCategoria $proposta,
        private readonly LoggerInterface $logger,
        private readonly TurnoProposte $turni,
        private readonly RateLimiterFactoryInterface $propostaCategoriaLimiter,
        private readonly LavoriClassificazione $sulMac,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/documenti/{id}/proposta-categoria', name: 'api_documento_proposta_categoria', methods: ['POST'])]
    public function __invoke(string $tenantId, string $id, Request $request): JsonResponse
    {
        $tenant = $this->tenants->findByPublicId($tenantId) ?? throw new NotFoundHttpException('Tenant non trovato.');
        $documento = $this->documenti->findOneInTenant($tenant, $id);
        // Chi non può leggerlo (es. accesso limitato ad altre categorie) non sa nemmeno che esiste.
        if (null === $documento || !$this->access->isGranted('documento:read', $documento)) {
            throw new NotFoundHttpException('Documento non trovato.');
        }
        $this->access->require('documento:write', $documento);
        if (!$this->proposta->isEnabled()) {
            throw new ConflictHttpException('L\'AI non è configurata su questo server (OLLAMA_MODEL).');
        }
        $body = $this->body($request);
        $nuove = $body['nuove'] ?? [];
        if (!\is_array($nuove) || \count($nuove) > self::MAX_NUOVE) {
            throw new UnprocessableEntityHttpException(sprintf('nuove: al massimo %d nomi.', self::MAX_NUOVE));
        }
        $escludi = $body['escludi'] ?? [];
        if (!\is_array($escludi) || \count($escludi) > self::MAX_ESCLUDI) {
            throw new UnprocessableEntityHttpException(sprintf('escludi: al massimo %d documenti.', self::MAX_ESCLUDI));
        }

        $nuove = array_values(array_filter($nuove, 'is_string'));
        $escludi = array_values(array_filter($escludi, 'is_string'));
        $utente = $this->access->currentUser()->getId()->toRfc4122();
        $stato = $this->sulMac->proposta($documento);
        if (\is_array($stato)) {
            return $this->json($stato['esito']);
        }
        if ('in_corso' === $stato) {
            throw new TooManyRequestsHttpException(self::RIPROVA_SUL_MAC, self::SUL_MAC);
        }
        if (null === $stato) {
            $limite = $this->propostaCategoriaLimiter->create($utente)->consume();
            if (!$limite->isAccepted()) {
                throw new TooManyRequestsHttpException(max(1, $limite->getRetryAfter()->getTimestamp() - time()), 'Troppe proposte nell\'ultima ora: riprova più tardi.');
            }
            if ($this->sulMac->avviaProposta($documento, $nuove, $escludi)) {
                throw new TooManyRequestsHttpException(self::RIPROVA_SUL_MAC, self::SUL_MAC);
            }
        }

        // Il modello locale su CPU può metterci decine di secondi, fino a tre richieste: in FrankenPHP
        // max_execution_time conta anche le attese, e i 30 secondi predefiniti non bastano.
        set_time_limit(700);
        try {
            return $this->json($this->turni->esegui($utente, fn () => $this->proposta->proponi($documento, $nuove, $escludi)));
        } catch (TurnoOccupato $e) {
            // Retry-After breve: il wizard aspetta e riprova lo stesso documento.
            throw new TooManyRequestsHttpException(self::RIPROVA_TRA, $e->getMessage(), $e);
        } catch (\RuntimeException $e) {
            $this->logger->warning('Proposta di categoria non riuscita per {id}: {errore}', ['id' => $id, 'errore' => $e->getMessage()]);
            throw new ServiceUnavailableHttpException(null, 'Il modello AI non risponde: riprova tra poco.', $e);
        }
    }
}
