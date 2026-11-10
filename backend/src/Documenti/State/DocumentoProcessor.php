<?php

namespace App\Documenti\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Documenti\ApiResource\DocumentoResource;
use App\Documenti\DocumentAudit;
use App\Documenti\DocumentService;
use App\Documenti\Repository\CategoriaRepository;
use App\Documenti\Repository\DocumentoRepository;
use App\Tenancy\Repository\MembroFamigliaRepository;
use App\Tenancy\Repository\TenantRepository;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Modifica (documento:write) e cancellazione (documento:delete). Con lo scope
 * ":own" il voter consente l'operazione solo al proprietario del documento.
 *
 * @implements ProcessorInterface<DocumentoResource, DocumentoResource|null>
 */
final class DocumentoProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly TenantRepository $tenants,
        private readonly DocumentoRepository $documenti,
        private readonly CategoriaRepository $categorie,
        private readonly MembroFamigliaRepository $membri,
        private readonly DocumentService $documents,
        private readonly DocumentAudit $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?DocumentoResource
    {
        $tenant = $this->tenants->findByPublicId((string) $uriVariables['tenantId']) ?? throw new NotFoundHttpException('Tenant non trovato.');
        $documento = $this->documenti->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Documento non trovato.');

        if ($operation instanceof DeleteOperationInterface) {
            $this->access->require('documento:delete', $documento);
            $this->documents->delete($documento);

            return null;
        }

        $this->access->require('documento:write', $documento);
        \assert($data instanceof DocumentoResource);
        $changes = [];

        // Prima si valida tutto: il cambio verso una categoria sensibile cifra e salva subito.
        $dataDocumento = $this->date($data->dataDocumento, 'dataDocumento');
        $dataConservazioneFino = $this->date($data->dataConservazioneFino, 'dataConservazioneFino');
        $membri = $this->membri->findAllInTenant($tenant, array_map('strval', $data->membriIds))
            ?? throw new UnprocessableEntityHttpException('membriIds: uno o più membri non appartengono a questa famiglia.');
        $categoria = null;
        if (null !== $data->categoriaId && $data->categoriaId !== (string) $documento->getCategoria()->getId()) {
            $categoria = $this->categorie->findUsableById($tenant, $data->categoriaId)
                ?? throw new UnprocessableEntityHttpException('categoriaId: categoria non valida per questa famiglia.');
        }

        // Poi la categoria, per prima: il documento viene riletto (e cifrato) sul suo stato attuale.
        if (null !== $categoria) {
            $this->documents->changeCategoria($documento, $categoria);
            $changes[] = 'categoria';
        }
        if (trim($data->titolo) !== $documento->getTitolo()) {
            $documento->setTitolo(trim($data->titolo));
            $changes[] = 'titolo';
        }
        $documento->setDataDocumento($dataDocumento);
        $documento->setDataConservazioneFino($dataConservazioneFino);
        $documento->replaceMembri($membri);

        $this->em->flush();
        $this->audit->write($documento, 'documento.updated', ['campi' => $changes]);

        return DocumentoResource::fromEntity($documento, $this->documents->readOcrText($documento));
    }

    private function date(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: throw new UnprocessableEntityHttpException($field.': formato atteso AAAA-MM-GG.');
    }
}
