<?php

namespace App\Pratiche\Command;

use App\Pratiche\AiVociSuggeritore;
use App\Pratiche\Allenamento\Anonimizzatore;
use App\Pratiche\Allenamento\EsempiPratica;
use App\Pratiche\Repository\PraticaRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Esempi per allenare il modello (LoRA) dalle pratiche già riviste, in JSONL
 * nel formato chat ({"messages": [system, user, assistant]}): lo stesso prompt
 * dell'analisi e la risposta giusta (le voci confermate).
 *
 * Anonimizzati per impostazione (Anonimizzatore): nomi delle persone della
 * famiglia e degli utenti, codici fiscali, IBAN, targhe, email, telefoni,
 * numeri lunghi e importi sostituiti con valori finti coerenti. Il file
 * resta sul server: va guardato prima di copiarlo altrove. --in-chiaro solo
 * per prove, e chiede conferma.
 */
#[AsCommand(name: 'app:pratiche:esporta-esempi', description: 'Esporta gli esempi anonimizzati delle pratiche riviste per allenare il modello')]
final class EsportaEsempiCommand extends Command
{
    public function __construct(
        private readonly PraticaRepository $pratiche,
        private readonly EsempiPratica $esempi,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pratica', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Id della pratica (anche più volte)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tutte le pratiche di questa famiglia (id)')
            ->addOption('uscita', null, InputOption::VALUE_REQUIRED, 'File JSONL da scrivere (sul server)')
            ->addOption('negativi', null, InputOption::VALUE_NONE, 'Anche i documenti analizzati senza proposte, come "qui non c\'è nulla"')
            ->addOption('in-chiaro', null, InputOption::VALUE_NONE, 'Senza anonimizzare (solo per prove; chiede conferma)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uscita = $input->getOption('uscita');
        if (!\is_string($uscita) || '' === $uscita) {
            $io->error('Indica --uscita=<file.jsonl>.');

            return Command::INVALID;
        }
        $pratiche = ValutaAnalisiCommand::pratiche($this->pratiche, $input, $io);
        if (null === $pratiche) {
            return Command::INVALID;
        }
        $inChiaro = (bool) $input->getOption('in-chiaro');
        if ($inChiaro && !$io->confirm('Gli esempi conterranno i dati veri dei documenti (nomi, codici fiscali, IBAN, importi). Continuare?', false)) {
            return Command::FAILURE;
        }
        $f = fopen($uscita, 'w');
        if (false === $f) {
            $io->error("Non riesco a scrivere $uscita.");

            return Command::FAILURE;
        }
        chmod($uscita, 0600);
        $positivi = 0;
        $negativi = 0;
        $n = 0;
        foreach ($pratiche as $p) {
            // Nomi da togliere: persone della pratica e della famiglia, e chi ha l'account.
            $nomi = [];
            foreach ($p->getPersone() as $m) {
                $nomi[] = $m->getDisplayName();
                if (null !== $m->getUser()) {
                    $nomi[] = $m->getUser()->getFullName();
                }
            }
            $nomi[] = $p->getOwner()->getFullName();
            // Anche chi ha un account nella famiglia e gli intestatari riconosciuti nei documenti.
            array_push($nomi, ...$this->em->getConnection()->fetchFirstColumn(
                "SELECT trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')) FROM membership m JOIN app_user u ON u.id = m.user_id WHERE m.tenant_id = :t
                 UNION SELECT dati->>'intestatario' FROM documento_indice WHERE tenant_id = :t AND coalesce(dati->>'intestatario', '') <> ''",
                ['t' => $p->getTenant()->getId()->toRfc4122()],
            ));
            foreach ($this->esempi->esempi($p, (bool) $input->getOption('negativi')) as $e) {
                ++$n;
                $anon = $inChiaro ? null : new Anonimizzatore($nomi);
                $testo = null === $anon ? $e['testo'] : $anon->testo($e['testo']);
                $oro = null === $anon ? $e['oro'] : array_map(static fn (array $o) => ['valori' => $anon->valori($o['valori'])] + $o, $e['oro']);
                $nomeFile = $inChiaro ? $e['documento']->getNomeFileOriginale() : sprintf('documento-%d.%s', $n, strtolower(pathinfo($e['documento']->getNomeFileOriginale(), \PATHINFO_EXTENSION) ?: 'pdf'));
                $richiesta = AiVociSuggeritore::richiesta($e['requisiti'], $nomeFile, AiVociSuggeritore::testoPerModello($e['requisiti'], $testo, 6000));
                $risposta = EsempiPratica::risposta($e['modello'], $oro, $e['requisiti'], $testo, static fn (string $nome) => null === $anon ? $nome : $anon->nomePersona($nome));
                fwrite($f, json_encode(['messages' => [
                    ['role' => 'system', 'content' => AiVociSuggeritore::istruzioni($e['modello']->titolo)],
                    ['role' => 'user', 'content' => $richiesta['prompt']],
                    ['role' => 'assistant', 'content' => json_encode($risposta, \JSON_UNESCAPED_UNICODE)],
                ]], \JSON_UNESCAPED_UNICODE)."\n");
                [] === $e['oro'] ? ++$negativi : ++$positivi;
            }
        }
        fclose($f);
        $io->success(sprintf('%d esempi in %s (%d con voci, %d "qui non c\'è nulla")%s.', $positivi + $negativi, $uscita, $positivi, $negativi, $inChiaro ? ', IN CHIARO' : ', anonimizzati'));
        if (!$inChiaro) {
            $io->note('L\'anonimizzazione non è perfetta (indirizzi, nomi non della famiglia restano): guarda il file prima di copiarlo fuori dal server.');
        }

        return Command::SUCCESS;
    }
}
