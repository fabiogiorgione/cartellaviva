<?php

namespace App\Account\Command;

use App\Account\Anagrafica\GeoImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:geo:import', description: 'Importa stati e comuni italiani (codici ISTAT e Belfiore)')]
final class ImportGeoCommand
{
    public function __construct(private readonly GeoImporter $importer)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->importer->import();
        $io->success(sprintf('Importati %d stati e %d comuni.', $result['countries'], $result['comuni']));

        return Command::SUCCESS;
    }
}
