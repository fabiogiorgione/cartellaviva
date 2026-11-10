<?php

namespace App\Tenancy\Permission\DependencyInjection;

use App\Tenancy\Permission\Attribute\PermissionResource;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Costruisce il catalogo permessi a compile time (parametro app.permission_catalog)
 * unendo config/packages/permissions.yaml e gli attributi #[PermissionResource]
 * delle entità in src/Entity. In dev il container si ricompila quando cambia
 * un'entità; in prod il catalogo è una costante del container.
 */
final class PermissionCatalogPass implements CompilerPassInterface
{
    public const PARAMETER = 'app.permission_catalog';
    private const CODE_PATTERN = '/^[a-z][a-z_]*:[a-z_]+(:(own|all))?$/';

    public function process(ContainerBuilder $container): void
    {
        $catalog = [];

        /** @var array<string, array{label?: string, sensitive?: bool, actions?: array<string, string>}> $fromYaml */
        $fromYaml = $container->hasParameter('app.permissions') ? $container->getParameter('app.permissions') : [];
        foreach ($fromYaml as $resource => $definition) {
            $this->merge($catalog, (string) $resource, $definition['label'] ?? (string) $resource, (bool) ($definition['sensitive'] ?? true), $definition['actions'] ?? []);
        }

        // Le entità stanno nelle cartelle Entity/ dei domini (src/<Dominio>/Entity).
        $srcDir = $container->getParameter('kernel.project_dir').'/src';
        $container->addResource(new DirectoryResource($srcDir, '/\.php$/'));

        foreach ($this->entityClasses($srcDir) as $class) {
            foreach ((new \ReflectionClass($class))->getAttributes(PermissionResource::class) as $attribute) {
                $definition = $attribute->newInstance();
                $this->merge($catalog, $definition->resource, $definition->label, $definition->sensitive, $definition->actions);
            }
        }

        ksort($catalog);
        $container->setParameter(self::PARAMETER, $catalog);
    }

    /**
     * @param array<string, array{label: string, sensitive: bool, actions: array<string, string>}> $catalog
     * @param array<string, string>                                                                 $actions
     */
    private function merge(array &$catalog, string $resource, string $label, bool $sensitive, array $actions): void
    {
        $catalog[$resource] ??= ['label' => $label, 'sensitive' => $sensitive, 'actions' => []];
        $catalog[$resource]['sensitive'] = $catalog[$resource]['sensitive'] || $sensitive;

        foreach ($actions as $action => $actionLabel) {
            $code = $resource.':'.$action;
            if (1 !== preg_match(self::CODE_PATTERN, $code)) {
                throw new \InvalidArgumentException(sprintf('Codice permesso non valido "%s" (atteso risorsa:azione[:own|:all]).', $code));
            }
            $catalog[$resource]['actions'][(string) $action] = (string) $actionLabel;
        }
    }

    /** @return iterable<class-string> */
    private function entityClasses(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), \strlen($dir) + 1, -4);
            if ('php' !== $file->getExtension() || !preg_match('#(^|/)Entity/#', $relative)) {
                continue;
            }
            $class = 'App\\'.str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                yield $class;
            }
        }
    }
}
