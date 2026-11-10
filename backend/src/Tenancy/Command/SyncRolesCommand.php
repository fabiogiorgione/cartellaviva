<?php

namespace App\Tenancy\Command;

use App\Tenancy\Permission\DefaultRolesSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:roles:sync', description: 'Crea/aggiorna ruoli e gruppi di permessi di sistema da config/packages/rbac.yaml')]
final class SyncRolesCommand
{
    public function __construct(private readonly DefaultRolesSynchronizer $synchronizer)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->synchronizer->synchronize();
        $io->writeln('Gruppi: '.implode(', ', $result['groups']));
        $io->writeln('Ruoli: '.implode(', ', $result['roles']));
        $io->success('Ruoli di sistema sincronizzati.');

        return Command::SUCCESS;
    }
}
