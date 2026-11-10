<?php

namespace App\Import\Provider;

use App\Import\Enum\ImportProvider;
use App\Import\Exception\ProviderNonConfiguratoException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ImportProviderRegistry
{
    /** @var array<string, ImportProviderInterface> */
    private array $providers = [];

    /** @param iterable<ImportProviderInterface> $providers */
    public function __construct(#[AutowireIterator('app.import_provider')] iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->provider()->value] = $provider;
        }
    }

    public function get(ImportProvider $provider): ImportProviderInterface
    {
        return $this->providers[$provider->value] ?? throw new ProviderNonConfiguratoException($provider);
    }

    /** Provider con le credenziali OAuth presenti; altrimenti ProviderNonConfiguratoException. */
    public function getConfigured(ImportProvider $provider): ImportProviderInterface
    {
        $instance = $this->get($provider);
        if (!$instance->isConfigured()) {
            throw new ProviderNonConfiguratoException($provider);
        }

        return $instance;
    }
}
