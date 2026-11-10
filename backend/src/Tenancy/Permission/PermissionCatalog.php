<?php

namespace App\Tenancy\Permission;

use App\Tenancy\Permission\DependencyInjection\PermissionCatalogPass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Catalogo permessi compilato (vedi PermissionCatalogPass). È la fonte di verità
 * per i codici validi; la tabella "permission" ne è la proiezione a DB.
 */
final class PermissionCatalog
{
    /**
     * @param array<string, array{label: string, sensitive: bool, actions: array<string, string>}> $resources
     */
    public function __construct(
        #[Autowire(param: PermissionCatalogPass::PARAMETER)]
        private readonly array $resources,
    ) {
    }

    /**
     * @return array<string, array{code: string, resource: string, action: string, label: string, resourceLabel: string, sensitive: bool}>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->resources as $resource => $definition) {
            foreach ($definition['actions'] as $action => $label) {
                $code = $resource.':'.$action;
                $all[$code] = [
                    'code' => $code,
                    'resource' => $resource,
                    'action' => $action,
                    'label' => $label,
                    'resourceLabel' => $definition['label'],
                    'sensitive' => $definition['sensitive'],
                ];
            }
        }

        return $all;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    public function has(string $code): bool
    {
        [$resource, $action] = array_pad(explode(':', $code, 2), 2, '');

        return isset($this->resources[$resource]['actions'][$action]);
    }

    /**
     * Un attributo richiesto nei controlli (senza scope) è valido se esiste così
     * com'è oppure nelle varianti ":own" / ":all".
     */
    public function isCheckableAttribute(string $attribute): bool
    {
        return $this->has($attribute) || $this->has($attribute.':own') || $this->has($attribute.':all');
    }

    public function isSensitive(string $code): bool
    {
        $resource = explode(':', $code, 2)[0];

        return $this->resources[$resource]['sensitive'] ?? true;
    }
}
