<?php

namespace App\Pratiche\Command;

use App\Documenti\Ocr\OcrEngineInterface;
use App\Pratiche\EstrattoreVoci;
use App\Pratiche\Modello\ModelliPratica;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Banco di prova: una cartella di documenti raccolti a mano per una pratica
 * (es. quelli portati al CAF per l'ISEE) passa dalla stessa lettura e dalle
 * stesse regole dei documenti caricati, e per ogni file si vede se verrebbe
 * selezionato e per quale requisito. Con --attesi il risultato si confronta
 * con quello giusto, scritto a mano una volta; con --ai si chiede anche le
 * voci al modello. Non salva nulla nel database: il testo resta in memoria.
 *
 * I documenti si considerano dentro la finestra di date della pratica.
 */
#[AsCommand(name: 'app:pratiche:prova-cartella', description: 'Prova le regole di una pratica su una cartella di documenti raccolti a mano')]
final class ProvaCartellaCommand extends Command
{
    private const MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'heic' => 'image/heic', 'heif' => 'image/heif', 'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain',
    ];
    public const NESSUNO = 'nessuno';

    public function __construct(
        private readonly ModelliPratica $modelli,
        private readonly OcrEngineInterface $ocr,
        private readonly EstrattoreVoci $estrattore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('cartella', InputArgument::REQUIRED, 'Cartella con i documenti (anche in sottocartelle)')
            ->addOption('modello', null, InputOption::VALUE_REQUIRED, 'Modello di pratica', 'isee')
            ->addOption('anno', null, InputOption::VALUE_REQUIRED, 'Anno della pratica')
            ->addOption('attesi', null, InputOption::VALUE_REQUIRED, 'File JSON {"percorso/file.pdf": "requisito" | "nessuno"} con il risultato giusto')
            ->addOption('ai', null, InputOption::VALUE_NONE, 'Chiede anche le voci al modello (lento: da secondi a un minuto per documento)')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Scrive anche il risultato in questo file JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cartella = rtrim((string) $input->getArgument('cartella'), '/');
        $modello = $this->modelli->trova((string) $input->getOption('modello'), (int) $input->getOption('anno'));
        if (null === $modello || !is_dir($cartella)) {
            $io->error(null === $modello ? 'Non c\'è un modello di pratica per questo anno (--modello, --anno).' : 'La cartella non esiste.');

            return Command::INVALID;
        }
        $attesi = null;
        if (null !== $input->getOption('attesi')) {
            $attesi = json_decode((string) @file_get_contents((string) $input->getOption('attesi')), true);
            if (!\is_array($attesi)) {
                $io->error('Il file degli attesi non è un JSON valido.');

                return Command::INVALID;
            }
        }
        $conAi = (bool) $input->getOption('ai');

        $righe = [];
        $conta = ['giusti' => 0, 'persi' => 0, 'sbagliati' => 0, 'in_piu' => 0, 'senza_atteso' => 0, 'voce_giusta' => 0, 'voce_mancante' => 0];
        foreach ($this->file($cartella) as $percorso) {
            $relativo = substr($percorso, \strlen($cartella) + 1);
            $mime = self::MIME[strtolower(pathinfo($percorso, \PATHINFO_EXTENSION))] ?? null;
            $riga = ['file' => $relativo, 'caratteri' => 0, 'candidati' => [], 'voci' => [], 'atteso' => null, 'esito' => null, 'errore' => null, 'secondi' => null];
            try {
                $testo = null === $mime ? '' : $this->ocr->extract((string) file_get_contents($percorso), $mime);
            } catch (\Throwable $e) {
                $testo = '';
                $riga['errore'] = mb_substr($e->getMessage(), 0, 80);
            }
            $riga['caratteri'] = mb_strlen(trim($testo));
            $nome = basename($percorso);
            $riga['candidati'] = array_map(static fn ($r) => $r->codice, EstrattoreVoci::candidati($modello, null, $testo, $nome));
            if ($conAi && [] !== $riga['candidati']) {
                $inizio = microtime(true);
                try {
                    foreach ($this->estrattore->estrai($modello, null, $nome, $testo, [], null)['voci'] as $v) {
                        // I valori restano nel JSON (file 0600, fuori dal repository): servono a confrontare i modelli.
                        $riga['voci'][] = ['requisito' => $v['requisito'], 'campi' => array_keys($v['valori']), 'tipo' => $v['valori']['tipo'] ?? null, 'valori' => $v['valori']];
                    }
                    $riga['secondi'] = round(microtime(true) - $inizio, 1);
                } catch (\Throwable $e) {
                    $riga['errore'] = mb_substr($e->getMessage(), 0, 80);
                }
            }
            unset($testo);

            if (null !== $attesi) {
                $atteso = $attesi[$relativo] ?? null;
                $riga['atteso'] = $atteso;
                $riga['esito'] = match (true) {
                    null === $atteso => 'senza_atteso',
                    self::NESSUNO === $atteso => [] === $riga['candidati'] ? 'giusti' : 'in_piu',
                    \in_array($atteso, $riga['candidati'], true) => 'giusti',
                    [] === $riga['candidati'] => 'persi',
                    default => 'sbagliati',
                };
                ++$conta[$riga['esito']];
                if ($conAi && null !== $atteso && self::NESSUNO !== $atteso) {
                    ++$conta[\in_array($atteso, array_column($riga['voci'], 'requisito'), true) ? 'voce_giusta' : 'voce_mancante'];
                }
            }
            $righe[] = $riga;
            $io->writeln(sprintf('  %s: %s', $relativo, [] === $riga['candidati'] ? '-' : implode(', ', $riga['candidati'])), OutputInterface::VERBOSITY_VERBOSE);
        }

        $etichette = ['giusti' => 'giusto', 'persi' => 'PERSO', 'sbagliati' => 'SBAGLIATO', 'in_piu' => 'IN PIÙ', 'senza_atteso' => '?'];
        $io->table(
            array_values(array_filter(['File', 'Testo', null !== $attesi ? 'Atteso' : null, 'Selezionato per', $conAi ? 'Voci' : null, null !== $attesi ? 'Esito' : null])),
            array_map(static fn (array $r) => array_values(array_filter([
                mb_strimwidth($r['file'], 0, 52, '…'),
                null !== $r['errore'] ? 'errore' : (string) $r['caratteri'],
                null !== $attesi ? ($r['atteso'] ?? '?') : null,
                [] === $r['candidati'] ? '-' : implode(', ', $r['candidati']),
                $conAi ? ([] === $r['voci'] ? '-' : implode(', ', array_map(static fn (array $v) => $v['requisito'].(null !== $v['tipo'] ? ':'.$v['tipo'] : ''), $r['voci']))) : null,
                null !== $attesi ? $etichette[$r['esito']] : null,
            ], static fn ($c) => null !== $c)), $righe),
        );
        if (null !== $attesi) {
            $conAtteso = \count($righe) - $conta['senza_atteso'];
            $io->writeln(sprintf('Selezione: %d giusti su %d · %d persi · %d con il requisito sbagliato · %d selezionati senza motivo', $conta['giusti'], $conAtteso, $conta['persi'], $conta['sbagliati'], $conta['in_piu']));
            if ($conAi) {
                $io->writeln(sprintf('Voci: %d documenti con la voce del requisito giusto su %d che ne aspettano una', $conta['voce_giusta'], $conta['voce_giusta'] + $conta['voce_mancante']));
            }
        }
        if (null !== $input->getOption('json')) {
            file_put_contents((string) $input->getOption('json'), json_encode(['modello' => $modello->codice, 'anno' => $modello->anno, 'ai' => $conAi ? $this->estrattore->modelloAi() : null, 'conta' => $conta, 'file' => $righe], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
            @chmod((string) $input->getOption('json'), 0600);
        }

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function file(string $cartella): array
    {
        $file = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && !str_starts_with($f->getFilename(), '.') && 'json' !== strtolower($f->getExtension())) {
                $file[] = $f->getPathname();
            }
        }
        sort($file);

        return $file;
    }
}
