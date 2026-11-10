<?php

namespace App\Documenti\Command;

use App\Documenti\DocumentService;
use App\Documenti\Entity\Documento;
use App\Documenti\Enum\AnteprimaStato;
use App\Documenti\UploadPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mette in coda l'anteprima dei documenti che ne hanno bisogno e non ce l'hanno:
 * quelli caricati prima di questa funzione (es. foto HEIC) e, con --fallite,
 * quelli falliti (es. quando il convertitore non era configurato).
 */
#[AsCommand(name: 'app:anteprime:genera', description: 'Mette in coda le anteprime mancanti (HEIC → JPEG, Office → PDF)')]
final class GeneraAnteprimeCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentService $documents,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Rimette in coda anche le anteprime fallite')] bool $fallite = false): int
    {
        $stati = $fallite ? [AnteprimaStato::NON_SERVE, AnteprimaStato::FALLITA] : [AnteprimaStato::NON_SERVE];
        $n = 0;
        /** @var iterable<Documento> $documenti */
        $documenti = $this->em->createQuery('SELECT d FROM '.Documento::class.' d WHERE d.anteprimaStato IN (:stati) ORDER BY d.createdAt')
            ->setParameter('stati', $stati)->toIterable();
        foreach ($documenti as $d) {
            if (null !== UploadPolicy::anteprimaPer($d->getMimeType())) {
                $this->documents->rigeneraAnteprima($d);
                ++$n;
            }
        }
        $io->success(sprintf('Anteprime in coda: %d (le genera il worker).', $n));

        return Command::SUCCESS;
    }
}
