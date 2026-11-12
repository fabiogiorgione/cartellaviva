<?php

namespace App\Pratiche\Command;

use App\Documenti\Entity\Documento;
use App\Documenti\Enum\OcrStatus;
use App\Pratiche\Message\ProponiPagamentoMessage;
use App\Pratiche\MessageHandler\ProponiPagamentoHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Mette in coda la ricerca di avvisi di pagamento nei documenti già caricati
 * (con il testo già letto): quelli mai valutati; con --tutti anche quelli con
 * una proposta aperta o ignorata. Mai quelli che hanno già un pagamento.
 * Il lavoro lo fa il worker, un documento alla volta.
 */
#[AsCommand(name: 'app:pagamenti:proponi', description: 'Mette in coda la ricerca di avvisi di pagamento nei documenti già caricati')]
final class ProponiPagamentiCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Solo i documenti di questa famiglia (id)')
            ->addOption('tutti', null, InputOption::VALUE_NONE, 'Anche i documenti con una proposta già aperta o ignorata');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tutti = (bool) $input->getOption('tutti');
        $qb = $this->em->createQueryBuilder()->select('d')->from(Documento::class, 'd')
            ->andWhere('d.ocrStatus = :ok')->setParameter('ok', OcrStatus::COMPLETATO)
            ->orderBy('d.createdAt', 'ASC');
        $tenant = $input->getOption('tenant');
        if (null !== $tenant) {
            if (!\is_string($tenant) || !Uuid::isValid($tenant)) {
                $io->error('Id della famiglia non valido.');

                return Command::INVALID;
            }
            $qb->andWhere('d.tenant = :t')->setParameter('t', Uuid::fromString($tenant), 'uuid');
        }
        if (!$tutti) {
            $qb->andWhere('d.pagamentoPropostaStato IS NULL');
        }

        $coda = 0;
        foreach ($qb->getQuery()->toIterable() as $documento) {
            if (ProponiPagamentoHandler::isCandidate($this->em, $documento, $tutti)) {
                $this->bus->dispatch(new ProponiPagamentoMessage((string) $documento->getId(), $tutti));
                ++$coda;
            }
            $this->em->detach($documento);
        }
        $io->success(sprintf('%d documenti in coda per la ricerca di avvisi di pagamento.', $coda));

        return Command::SUCCESS;
    }
}
