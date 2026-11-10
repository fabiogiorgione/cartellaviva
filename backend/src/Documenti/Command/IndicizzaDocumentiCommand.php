<?php

namespace App\Documenti\Command;

use App\Documenti\Indice\EmbedderInterface;
use App\Documenti\Indice\IndiceDocumenti;
use App\Documenti\Message\IndicizzaDocumentoMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Mette in coda l'indicizzazione dei documenti non cifrati già presenti: quelli
 * mai indicizzati o indicizzati con un altro modello di embedding (--tutti: tutti).
 * Il lavoro lo fa il worker, un documento alla volta.
 */
#[AsCommand(name: 'app:documenti:indicizza', description: 'Mette in coda l\'indicizzazione (dati estratti, tag, ricerca per significato) dei documenti non cifrati')]
final class IndicizzaDocumentiCommand extends Command
{
    public function __construct(
        private readonly IndiceDocumenti $indice,
        private readonly EmbedderInterface $embedder,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tutti', null, InputOption::VALUE_NONE, 'Anche i documenti già indicizzati (es. dopo aver cambiato il modello o le regole di estrazione)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ids = $this->indice->daIndicizzare((bool) $input->getOption('tutti'), $this->embedder->isEnabled() ? $this->embedder->modello() : null);
        foreach ($ids as $id) {
            $this->bus->dispatch(new IndicizzaDocumentoMessage($id));
        }
        $io->success(sprintf('%d documenti in coda per l\'indicizzazione.', \count($ids)));

        return Command::SUCCESS;
    }
}
