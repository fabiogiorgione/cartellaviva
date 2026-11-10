<?php

namespace App\Documenti\Controller;

use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Enum\AnteprimaStato;
use App\Documenti\Repository\DocumentoRepository;
use App\Documenti\UploadPolicy;
use App\Shared\Http\RispostaFile;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Download e rielaborazione OCR. Il file passa SEMPRE da qui: permesso verificato
 * dal voter, decifratura in memoria, nessun link diretto allo storage.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DocumentoFileController extends AbstractController
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly DocumentoRepository $documenti,
        private readonly DocumentService $documents,
        private readonly DocumentAudit $audit,
    ) {
    }

    #[Route('/api/tenants/{tenantId}/documenti/{id}/file', name: 'api_documento_file', methods: ['GET'])]
    public function download(string $tenantId, string $id): Response
    {
        $documento = $this->load($tenantId, $id);
        $this->access->require('documento:read', $documento);

        $content = $this->documents->readContent($documento);
        $this->audit->read($documento, 'documento.download');

        return RispostaFile::allegato($content, $documento->getMimeType(), $documento->getNomeFileOriginale());
    }

    /**
     * Scheda "Anteprima": l'originale (PDF, JPEG, PNG) o la copia ricavata
     * (Office → PDF, HEIC → JPEG), decifrata in memoria; da mostrare, non da
     * scaricare. 202 se la copia è in preparazione, 409 se non è disponibile.
     */
    #[Route('/api/tenants/{tenantId}/documenti/{id}/anteprima', name: 'api_documento_anteprima', methods: ['GET'])]
    public function anteprima(string $tenantId, string $id): Response
    {
        $documento = $this->load($tenantId, $id);
        $this->access->require('documento:read', $documento);

        $anteprima = $this->documents->readAnteprima($documento);
        if (null === $anteprima) {
            $stato = $documento->getAnteprimaStato();

            $errore = UploadPolicy::haAnteprima($documento->getMimeType()) ? $documento->getAnteprimaErrore() : UploadPolicy::MOTIVO_SENZA_ANTEPRIMA;

            return $this->json(
                ['anteprimaStato' => $stato->value, 'anteprimaErrore' => $errore],
                AnteprimaStato::IN_CODA === $stato ? Response::HTTP_ACCEPTED : Response::HTTP_CONFLICT,
            );
        }
        $this->audit->read($documento, 'documento.anteprima');

        return RispostaFile::inline($anteprima[0], $anteprima[1], 'anteprima.'.UploadPolicy::extensionFor($anteprima[1]));
    }

    /** Rigenera la copia per l'anteprima (es. dopo un fallimento). Richiede documento:write. */
    #[Route('/api/tenants/{tenantId}/documenti/{id}/anteprima', name: 'api_documento_anteprima_rigenera', methods: ['POST'])]
    public function rigeneraAnteprima(string $tenantId, string $id): Response
    {
        $documento = $this->load($tenantId, $id);
        $this->access->require('documento:write', $documento);
        $this->documents->rigeneraAnteprima($documento);

        return $this->json(['anteprimaStato' => $documento->getAnteprimaStato()->value, 'ocrStatus' => $documento->getOcrStatus()->value], Response::HTTP_ACCEPTED);
    }

    /** Rimette in coda l'OCR (es. dopo un fallimento). Richiede documento:write. */
    #[Route('/api/tenants/{tenantId}/documenti/{id}/ocr', name: 'api_documento_ocr', methods: ['POST'])]
    public function requeueOcr(string $tenantId, string $id): Response
    {
        $documento = $this->load($tenantId, $id);
        $this->access->require('documento:write', $documento);
        $this->documents->requeueOcr($documento);

        return $this->json(['ocrStatus' => $documento->getOcrStatus()->value], Response::HTTP_ACCEPTED);
    }

    private function load(string $tenantId, string $id): \App\Documenti\Entity\Documento
    {
        $tenant = $this->tenants->findByPublicId($tenantId) ?? throw new NotFoundHttpException('Tenant non trovato.');
        // Il permesso di base sul tenant prima di rivelare se il documento esiste.
        $this->access->require('documento:read', $tenant);

        return $this->documenti->findOneInTenant($tenant, $id) ?? throw new NotFoundHttpException('Documento non trovato.');
    }
}
