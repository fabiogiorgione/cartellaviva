<?php

namespace App\Tenancy\Command;

use App\Tenancy\Permission\PermissionSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:permissions:sync', description: 'Allinea la tabella permission al catalogo (attributi sulle entità + permissions.yaml)')]
final class SyncPermissionsCommand
{
    public function __construct(private readonly PermissionSynchronizer $synchronizer)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->synchronizer->synchronize();

        $io->writeln(sprintf('Nuovi: %d, aggiornati: %d, obsoleti: %d', \count($result['created']), \count($result['updated']), \count($result['obsolete'])));
        if ($result['created']) {
            $io->listing($result['created']);
        }
        if ($result['obsolete']) {
            $io->warning('Permessi non più nel catalogo (marcati obsoleti): '.implode(', ', $result['obsolete']));
        }
        $io->success('Catalogo permessi sincronizzato.');

        return Command::SUCCESS;
    }
}
