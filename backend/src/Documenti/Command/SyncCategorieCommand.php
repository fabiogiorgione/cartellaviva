<?php

namespace App\Documenti\Command;

use App\Documenti\CategorieSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:categorie:sync', description: 'Crea/aggiorna le categorie di sistema da config/packages/categorie.yaml')]
final class SyncCategorieCommand
{
    public function __construct(private readonly CategorieSynchronizer $synchronizer)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success('Categorie di sistema: '.implode(', ', $this->synchronizer->synchronize()));

        return Command::SUCCESS;
    }
}
