<?php

namespace App\Documenti\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Documenti\ApiResource\DocumentoResource;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\CategoriaRepository;
use App\Tenancy\Repository\MembroFamigliaRepository;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Caricamento di un documento (multipart/form-data).
 * Ordine dei controlli: 1) permesso documento:create sul tenant (voter), PRIMA di
 * leggere il file; 2) categoria e membri del tenant; 3) tipo e dimensione del file;
 * 4) hash/duplicati; 5) cifratura se la categoria è sensibile; 6) salvataggio;
 * 7) messaggio OCR.
 *
 * @implements ProcessorInterface<null, DocumentoResource>
 */
final class DocumentoUploadProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly CategoriaRepository $categorie,
        private readonly MembroFamigliaRepository $membri,
        private readonly DocumentService $documents,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DocumentoResource
    {
        $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], 'documento:create');
        $actor = $this->access->currentUser();

        $request = $context['request'] ?? null;
        \assert($request instanceof Request);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Campo "file" mancante (multipart/form-data).');
        }
        if (!$file->isValid()) {
            throw new UnprocessableEntityHttpException('Caricamento del file non riuscito: '.$file->getErrorMessage());
        }

        $categoria = $this->categorie->findUsableById($tenant, (string) $request->request->get('categoriaId', ''))
            ?? throw new UnprocessableEntityHttpException('categoriaId: categoria non valida per questa famiglia.');

        $membriIds = $request->request->all()['membriIds'] ?? [];
        $membri = $this->membri->findAllInTenant($tenant, array_map('strval', \is_array($membriIds) ? $membriIds : [$membriIds]))
            ?? throw new UnprocessableEntityHttpException('membriIds: uno o più membri non appartengono a questa famiglia.');

        $dataDocumento = null;
        $rawData = (string) $request->request->get('dataDocumento', '');
        if ('' !== $rawData) {
            $dataDocumento = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawData) ?: throw new UnprocessableEntityHttpException('dataDocumento: formato atteso AAAA-MM-GG.');
        }
        $titolo = trim((string) $request->request->get('titolo', ''));
        if (mb_strlen($titolo) > 200) {
            throw new UnprocessableEntityHttpException('titolo: massimo 200 caratteri.');
        }

        $result = $this->documents->upload($actor, $tenant, $categoria, $membri, $file->getContent(), $file->getClientOriginalName(), $titolo ?: null, $dataDocumento);

        $resource = DocumentoResource::fromEntity($result['documento']);
        $resource->possibiliDuplicati = array_map(static fn (Documento $d) => [
            'id' => (string) $d->getId(),
            'titolo' => $d->getTitolo(),
            'createdAt' => $d->getCreatedAt()->format(\DATE_ATOM),
        ], $result['duplicati']);

        return $resource;
    }
}
