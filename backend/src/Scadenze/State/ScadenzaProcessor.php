<?php

namespace App\Scadenze\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Documenti\Entity\Documento;
use App\Documenti\Repository\CategoriaRepository;
use App\Documenti\Repository\DocumentoRepository;
use App\Scadenze\ApiResource\ScadenzaResource;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Enum\ScadenzaOrigine;
use App\Scadenze\Enum\ScadenzaRicorrenza;
use App\Scadenze\Repository\ScadenzaRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Enum\AuditOutcome;
use App\Tenancy\Entity\Tenant;
use App\Tenancy\Repository\MembroFamigliaRepository;
use App\Tenancy\Security\TenantAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Creazione, modifica e cancellazione delle scadenze.
 *
 * Il permesso scadenza:manage si verifica SEMPRE sulla scadenza (anche nuova,
 * con l'utente come proprietario): così lo scope ":own" consente di creare le
 * proprie e di gestire solo quelle. Le scadenze nate da una richiesta di un ente
 * non si modificano a mano.
 *
 * @implements ProcessorInterface<ScadenzaResource, ScadenzaResource|null>
 */
final class ScadenzaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly ScadenzaRepository $scadenze,
        private readonly DocumentoRepository $documenti,
        private readonly MembroFamigliaRepository $membri,
        private readonly CategoriaRepository $categorie,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ScadenzaResource
    {
        $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], 'scadenza:read');

        if ($operation instanceof Post) {
            \assert($data instanceof ScadenzaResource);
            $scadenza = new Scadenza($tenant, $this->access->currentUser(), trim($data->titolo), $this->date($data->data));
            $this->access->require('scadenza:manage', $scadenza);
            $this->apply($tenant, $scadenza, $data);
            $this->em->persist($scadenza);
            $this->em->flush();
            $this->log('scadenza.created', $scadenza);

            return ScadenzaResource::fromEntity($scadenza);
        }

        $scadenza = $this->scadenze->findOneInTenant($tenant, (string) $uriVariables['id']) ?? throw new NotFoundHttpException('Scadenza non trovata.');
        $this->access->require('scadenza:manage', $scadenza);
        if (ScadenzaOrigine::RICHIESTA === $scadenza->getOrigine()) {
            throw new ConflictHttpException('Questa scadenza segue una richiesta di un ente: si aggiorna da sola quando rispondi.');
        }

        if ($operation instanceof DeleteOperationInterface) {
            $this->log('scadenza.deleted', $scadenza);
            $this->em->remove($scadenza);
            $this->em->flush();

            return null;
        }

        \assert($data instanceof ScadenzaResource);
        $this->apply($tenant, $scadenza, $data);
        $this->em->flush();
        $this->log('scadenza.updated', $scadenza);

        return ScadenzaResource::fromEntity($scadenza);
    }

    private function apply(Tenant $tenant, Scadenza $scadenza, ScadenzaResource $data): void
    {
        $documento = null;
        if (null !== $data->documentoId && '' !== $data->documentoId) {
            $documento = $this->documenti->findOneInTenant($tenant, $data->documentoId)
                ?? throw new UnprocessableEntityHttpException('documentoId: documento non trovato in questa famiglia.');
            $this->access->require('documento:read', $documento);
        }
        $membro = null;
        if (null !== $data->membroId && '' !== $data->membroId) {
            $membro = $this->membri->findOneInTenant($tenant, $data->membroId)
                ?? throw new UnprocessableEntityHttpException('membroId: persona non trovata in questa famiglia.');
        }
        $categoria = null;
        if (null !== $data->categoriaId && '' !== $data->categoriaId) {
            $categoria = $this->categorie->findUsableById($tenant, $data->categoriaId)
                ?? throw new UnprocessableEntityHttpException('categoriaId: categoria non valida per questa famiglia.');
        }

        $scadenza->update(
            trim($data->titolo),
            $data->note,
            $this->date($data->data),
            ScadenzaRicorrenza::from($data->ricorrenza),
            $data->promemoriaGiorni,
            $documento,
            $membro,
            $categoria,
        );
        // Scadenza creata (anche da una proposta) per questo documento: la proposta è decisa.
        $documento?->chiudiScadenzaProposta(Documento::PROPOSTA_ACCETTATA);
    }

    private function date(?string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value) ?: throw new UnprocessableEntityHttpException('data: formato atteso AAAA-MM-GG.');
    }

    private function log(string $action, Scadenza $scadenza): void
    {
        $this->audit->log($action, AuditOutcome::SUCCESS, resourceType: 'scadenza', resourceId: (string) $scadenza->getId(),
            tenantId: $scadenza->getTenant()->getId(), context: ['data' => $scadenza->getData()->format('Y-m-d'), 'ricorrenza' => $scadenza->getRicorrenza()->value]);
    }
}
