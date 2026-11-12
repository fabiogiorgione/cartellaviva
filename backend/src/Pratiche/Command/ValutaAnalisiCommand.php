<?php

namespace App\Pratiche\Command;

use App\Pratiche\Allenamento\EsempiPratica;
use App\Pratiche\Allenamento\Valutazione;
use App\Pratiche\Entity\Pratica;
use App\Pratiche\EstrattoreVoci;
use App\Pratiche\Repository\PraticaRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Quanto è brava l'analisi dei documenti (Ollama, o le regole senza AI) sulle
 * pratiche già riviste: rilancia l'analisi su ogni documento e confronta con
 * le voci confermate. Non salva nulla; il testo si legge solo in memoria.
 * Con Ollama sul server ogni documento richiede da qualche secondo a un minuto.
 */
#[AsCommand(name: 'app:pratiche:valuta', description: "Misura l'analisi dei documenti sulle pratiche già riviste")]
final class ValutaAnalisiCommand extends Command
{
    public function __construct(
        private readonly PraticaRepository $pratiche,
        private readonly EsempiPratica $esempi,
        private readonly EstrattoreVoci $estrattore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pratica', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Id della pratica (anche più volte)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tutte le pratiche di questa famiglia (id)')
            ->addOption('documento', null, InputOption::VALUE_REQUIRED, 'Solo questo documento (id)')
            ->addOption('negativi', null, InputOption::VALUE_NONE, 'Anche i documenti analizzati senza proposte (contano come "qui non c\'è nulla")')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Al massimo questi documenti', '1000')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Scrive anche il risultato in questo file JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pratiche = self::pratiche($this->pratiche, $input, $io);
        if (null === $pratiche) {
            return Command::INVALID;
        }
        $limite = max(1, (int) $input->getOption('limite'));
        $v = new Valutazione();
        $errori = [];
        foreach ($pratiche as $p) {
            foreach ($this->esempi->esempi($p, (bool) $input->getOption('negativi'), $input->getOption('documento')) as $e) {
                if ($v->documenti >= $limite) {
                    break 2;
                }
                $membri = array_values(array_filter($e['documento']->getMembri()->toArray(), static fn ($m) => \in_array($m, $p->getPersone(), true)));
                try {
                    $r = $this->estrattore->estrai($e['modello'], $e['documento']->getCategoria()->getSlug(), $e['documento']->getNomeFileOriginale(), $e['testo'], $p->getPersone(), 1 === \count($membri) ? $membri[0] : null);
                } catch (\Throwable $t) {
                    $errori[] = $t->getMessage();
                    continue;
                }
                $v->aggiungi($e['modello'], $e['oro'], $r['voci'], $e['nonCandidati']);
                $io->writeln(sprintf('  %s: %d giuste, %d proposte (%s)', $e['documento']->getTitolo(), \count($e['oro']), \count($r['voci']), $r['metodo']), OutputInterface::VERBOSITY_VERBOSE);
            }
        }
        if (0 === $v->documenti) {
            $io->warning('Nessun documento rivisto: conferma, correggi o escludi le proposte nella pratica, poi riprova.');

            return Command::SUCCESS;
        }

        $pct = static fn (int $a, int $b): string => $b > 0 ? sprintf('%d%%', (int) round(100 * $a / $b)) : '-';
        $righe = [];
        foreach ($v->requisiti as $codice => $r) {
            $righe[] = [$codice, $r['oro'], $r['proposte'], $r['giuste'], $r['inPiu'], $r['mancate'], $pct($r['giuste'], $r['proposte']), $pct($r['giuste'], $r['oro'])];
        }
        $io->title(sprintf('Analisi su %d documenti', $v->documenti));
        $io->table(['Requisito', 'Giuste', 'Proposte', 'Indovinate', 'In più', 'Mancate', 'Precisione', 'Richiamo'], $righe);
        $campi = [];
        foreach ($v->campi as $k => $c) {
            $campi[] = [$k, $c['confronti'], $pct($c['uguali'], $c['confronti'])];
        }
        if ([] !== $campi) {
            $io->table(['Campo (sulle voci indovinate)', 'Confronti', 'Uguali'], $campi);
        }
        $t = $v->totali();
        $io->writeln(sprintf('Precisione %s (delle proposte, quante giuste) · Richiamo %s (delle voci giuste, quante trovate).', null === $t['precisione'] ? '-' : sprintf('%d%%', round(100 * $t['precisione'])), null === $t['richiamo'] ? '-' : sprintf('%d%%', round(100 * $t['richiamo']))));
        if ([] !== $v->nonCandidati) {
            $io->note('Voci giuste che l\'analisi non poteva trovare (mancano parole chiave o categoria nelle regole): '.implode(', ', array_map(static fn ($k, $n) => "$k ($n)", array_keys($v->nonCandidati), $v->nonCandidati)).'.');
        }
        if ([] !== $errori) {
            $io->warning(sprintf('%d documenti non analizzati: %s', \count($errori), implode('; ', array_unique($errori))));
        }
        $json = $input->getOption('json');
        if (\is_string($json) && '' !== $json) {
            file_put_contents($json, json_encode(['documenti' => $v->documenti, 'requisiti' => $v->requisiti, 'campi' => $v->campi, 'nonCandidati' => $v->nonCandidati, 'totali' => $t], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
            $io->writeln("Risultato in $json");
        }

        return Command::SUCCESS;
    }

    /** @return list<Pratica>|null */
    public static function pratiche(PraticaRepository $repo, InputInterface $input, SymfonyStyle $io): ?array
    {
        $out = [];
        foreach ((array) $input->getOption('pratica') as $id) {
            $p = \is_string($id) && Uuid::isValid($id) ? $repo->find(Uuid::fromString($id)) : null;
            if (null === $p) {
                $io->error("Pratica $id non trovata.");

                return null;
            }
            $out[] = $p;
        }
        $tenant = $input->getOption('tenant');
        if (\is_string($tenant)) {
            if (!Uuid::isValid($tenant)) {
                $io->error('Id della famiglia non valido.');

                return null;
            }
            array_push($out, ...$repo->findBy(['tenant' => Uuid::fromString($tenant)]));
        }
        if ([] === $out) {
            $io->error('Indica --pratica=<id> o --tenant=<id della famiglia>.');

            return null;
        }

        return $out;
    }
}
