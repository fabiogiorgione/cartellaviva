<?php

namespace App\Documenti\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Documenti\ApiResource\CategoriaResource;
use App\Documenti\DocumentService;
use App\Documenti\Entity\Categoria;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\CategoriaRepository;
use App\Scadenze\Entity\Scadenza;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\Entity\ContattoEmergenza;
use App\Tenancy\Entity\Membership;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Categorie personalizzate del tenant. Le categorie di sistema non si toccano via API.
 * Cambiare isSensibile NON riscrive i documenti esistenti: vale per i nuovi caricamenti.
 *
 * @implements ProcessorInterface<CategoriaResource, CategoriaResource|null>
 */
final class CategoriaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly CategoriaRepository $categorie,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly RequestStack $requests,
        private readonly DocumentService $documents,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?CategoriaResource
    {
        $tenantId = (string) $uriVariables['tenantId'];
        $tenant = $this->access->requireTenant($tenantId, 'categoria:manage');

        if ($operation instanceof Post) {
            \assert($data instanceof CategoriaResource);
            $categoria = new Categoria($tenant, trim($data->nome), $data->icona, strtolower($data->colore), $data->isSensibile);
            $this->em->persist($categoria);
            $this->em->flush();
            $this->log('categoria.created', $categoria);

            return CategoriaResource::fromEntity($categoria, $tenantId);
        }

        $categoria = $this->categorie->findUsableById($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Categoria non trovata.');
        if ($categoria->isSystem()) {
            throw new AccessDeniedHttpException('Le categorie di sistema non sono modificabili: crea una categoria personalizzata.');
        }

        if ($operation instanceof DeleteOperationInterface) {
            $this->elimina($tenant, $categoria, $this->requests->getCurrentRequest()?->query->get('spostaIn'));

            return null;
        }

        \assert($data instanceof CategoriaResource);
        $wasSensibile = $categoria->isSensibile();
        $categoria->update(trim($data->nome), $data->icona, $data->colore, $data->isSensibile);
        $this->em->flush();
        $this->log('categoria.updated', $categoria, ['sensibile_prima' => $wasSensibile]);

        return CategoriaResource::fromEntity($categoria, $tenantId);
    }

    /**
     * Con documenti serve la categoria di destinazione: documenti e scadenze
     * passano lì, poi la categoria sparisce (tutto in una transazione).
     * Come il cambio di categoria: verso una sensibile i documenti si cifrano,
     * quelli già cifrati restano cifrati.
     * Gli elenchi dell'accesso d'emergenza perdono la categoria eliminata.
     */
    private function elimina(Tenant $tenant, Categoria $categoria, mixed $spostaIn): void
    {
        $documenti = $this->categorie->countDocumenti($categoria);
        $destinazione = null;
        if ($documenti > 0) {
            if (!\is_string($spostaIn) || '' === $spostaIn) {
                throw new ConflictHttpException(sprintf('La categoria contiene %d documenti: scegli dove spostarli.', $documenti));
            }
            $destinazione = $this->categorie->findUsableById($tenant, $spostaIn);
            if (null === $destinazione || $destinazione->getId()->equals($categoria->getId())) {
                throw new UnprocessableEntityHttpException('spostaIn: scegli un\'altra categoria della famiglia.');
            }
        }

        $id = $categoria->getId();
        $this->em->wrapInTransaction(function () use ($tenant, $categoria, $destinazione, $id): void {
            if (null !== $destinazione) {
                // Uno per uno, come il cambio di categoria: verso una categoria sensibile
                // il documento (file, testo, anteprima) viene cifrato.
                foreach ($this->em->getRepository(Documento::class)->findBy(['tenant' => $tenant, 'categoria' => $categoria]) as $documento) {
                    $this->documents->changeCategoria($documento, $destinazione);
                }
                $this->em->createQuery('UPDATE '.Scadenza::class.' x SET x.categoria = :a WHERE x.categoria = :da AND x.tenant = :t')
                    ->setParameter('a', $destinazione->getId(), 'uuid')->setParameter('da', $id, 'uuid')->setParameter('t', $tenant->getId(), 'uuid')
                    ->execute();
            }
            foreach ($this->em->getRepository(Membership::class)->findBy(['tenant' => $tenant]) as $m) {
                $m->togliCategoriaConsentita($id->toRfc4122());
            }
            foreach ($this->em->getRepository(ContattoEmergenza::class)->findBy(['tenant' => $tenant]) as $c) {
                $c->togliCategoria($id->toRfc4122());
            }
            $this->em->remove($categoria);
            $this->em->flush();
        });
        $this->log('categoria.deleted', $categoria, ['documenti_spostati' => $documenti, 'spostati_in' => $destinazione?->getNome()]);
    }

    /** @param array<string, mixed> $context */
    private function log(string $action, Categoria $categoria, array $context = []): void
    {
        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'categoria', resourceId: (string) $categoria->getId(),
            tenantId: $categoria->getTenant()?->getId(), context: $context + ['nome' => $categoria->getNome(), 'sensibile' => $categoria->isSensibile()]);
    }
}
