<?php

namespace App\Scadenze\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Scadenze\ApiResource\ScadenzaResource;
use App\Scadenze\Entity\Scadenza;
use App\Scadenze\Enum\ScadenzaStato;
use App\Scadenze\Repository\ScadenzaRepository;
use App\Tenancy\Security\PermissionResolver;
use App\Tenancy\Security\TenantAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<ScadenzaResource>
 */
final class ScadenzaProvider implements ProviderInterface
{
    public function __construct(
        private readonly TenantAccess $access,
        private readonly ScadenzaRepository $scadenze,
        private readonly PermissionResolver $resolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $tenant = $this->access->requireTenant((string) $uriVariables['tenantId'], 'scadenza:read');
        // Accesso d'emergenza limitato ad alcune categorie: come i documenti, le
        // scadenze legate alle altre categorie per lui non esistono.
        $membership = $this->resolver->getActiveMembership($this->access->currentUser(), $tenant);
        $visibile = static fn (Scadenza $s): bool => null === $membership || (
            (null === $s->getDocumento() || $membership->consenteCategoria($s->getDocumento()->getCategoria()->getId()))
            && (null === $s->getCategoria() || $membership->consenteCategoria($s->getCategoria()->getId()))
        );

        if ($operation instanceof CollectionOperationInterface) {
            $query = ($context['request'] ?? null)?->query;
            $stato = $query?->get('stato');

            return array_map(static fn (Scadenza $s) => ScadenzaResource::fromEntity($s), array_values(array_filter($this->scadenze->search($tenant, [
                'da' => $this->date($query?->get('da'), 'da'),
                'a' => $this->date($query?->get('a'), 'a'),
                'stato' => null !== $stato && '' !== $stato ? (ScadenzaStato::tryFrom((string) $stato) ?? throw new UnprocessableEntityHttpException('stato: valore non valido.')) : null,
                'documentoId' => $query?->get('documentoId'),
                'membroId' => $query?->get('membroId'),
            ]), $visibile)));
        }

        $scadenza = $this->scadenze->findOneInTenant($tenant, (string) $uriVariables['id']);
        if (null === $scadenza || !$visibile($scadenza)) {
            throw new NotFoundHttpException('Scadenza non trovata.');
        }

        return ScadenzaResource::fromEntity($scadenza);
    }

    private function date(mixed $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value) ?: throw new UnprocessableEntityHttpException($field.': formato atteso AAAA-MM-GG.');
    }
}
