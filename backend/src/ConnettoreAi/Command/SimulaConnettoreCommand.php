<?php

namespace App\ConnettoreAi\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Solo per sviluppo e misure: fa il ciclo del connettore AI (docs/connettore-ai.md)
 * in PHP, contro un server e un Ollama. Con --nome fa anche l'abbinamento
 * (mostra il codice da approvare nell'app); con --token usa un token già avuto.
 * Non scrive su disco: il testo dei documenti passa solo in memoria.
 */
#[AsCommand(name: 'app:connettore-ai:simula', description: 'Fa il ciclo del connettore AI sul Mac (solo sviluppo e misure)')]
final class SimulaConnettoreCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(default::OLLAMA_URL)%')]
        private readonly ?string $ollamaPredefinito = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Indirizzo del server', 'http://localhost:8080')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Token di accesso del connettore')
            ->addOption('nome', null, InputOption::VALUE_REQUIRED, 'Abbina un nuovo connettore con questo nome (senza --token)')
            ->addOption('ollama', null, InputOption::VALUE_REQUIRED, 'Indirizzo di Ollama (predefinito: OLLAMA_URL)')
            ->addOption('modello', null, InputOption::VALUE_REQUIRED, 'Modello di Ollama', 'qwen3:4b-instruct-2507-q4_K_M')
            ->addOption('contesto', null, InputOption::VALUE_REQUIRED, 'Contesto del modello in token', '8192')
            ->addOption('minuti', null, InputOption::VALUE_REQUIRED, 'Smette dopo tanti minuti senza lavori', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $server = rtrim((string) $input->getOption('server'), '/');
        $ollama = rtrim((string) ($input->getOption('ollama') ?? $this->ollamaPredefinito ?? ''), '/');
        $modello = (string) $input->getOption('modello');
        $contesto = max(2048, (int) $input->getOption('contesto'));
        if ('' === $ollama) {
            $io->error('Manca l\'indirizzo di Ollama (--ollama o OLLAMA_URL).');

            return Command::INVALID;
        }
        $token = $input->getOption('token');
        if (null === $token) {
            $token = $this->abbina($io, $server, (string) ($input->getOption('nome') ?? 'Mac di prova'));
            if (null === $token) {
                return Command::FAILURE;
            }
        }
        $api = fn (string $metodo, string $percorso, ?array $json = null) => $this->http->request($metodo, $server.'/api/ai-connector-plugin'.$percorso, array_filter(['auth_bearer' => $token, 'json' => $json, 'timeout' => 30]));

        $me = $api('PUT', '/me', ['versione' => 'simula', 'sistema' => \PHP_OS_FAMILY, 'motore' => 'ollama', 'modello' => $modello, 'contesto' => $contesto]);
        if (200 !== $me->getStatusCode()) {
            $io->error(sprintf('Il server ha risposto %d a PUT /me.', $me->getStatusCode()));

            return Command::FAILURE;
        }
        $impostazioni = $me->toArray();
        $io->writeln(sprintf('Collegato a "%s". Modelli verificati: %s.', $impostazioni['famiglia']['nome'], implode(', ', $impostazioni['modelliVerificati'])));
        // La stessa formula dell'iPhone: (contesto − 1500) × 3, tra 1.500 e 12.000.
        $caratteri = max(1500, min((int) $impostazioni['caratteriMax'], ($contesto - 1500) * 3));
        $fatti = 0;
        $tempi = [];
        $ultimoLavoro = time();
        while (time() - $ultimoLavoro < 60 * max(1, (int) $input->getOption('minuti'))) {
            $risposta = $api('GET', '/lavori?limite=1&caratteri='.$caratteri);
            if (429 === $risposta->getStatusCode()) {
                sleep(5);
                continue;
            }
            $lavori = $risposta->toArray()['lavori'] ?? [];
            if ([] === $lavori) {
                sleep(5);
                $api('PUT', '/me', ['versione' => 'simula', 'motore' => 'ollama', 'modello' => $modello, 'contesto' => $contesto])->getStatusCode();
                continue;
            }
            foreach ($lavori as $l) {
                $inizio = microtime(true);
                try {
                    $r = $this->http->request('POST', $ollama.'/api/chat', ['timeout' => 240, 'json' => [
                        'model' => $modello,
                        'stream' => false,
                        'format' => json_decode($l['schema'], true),
                        'options' => ['temperature' => 0, 'num_ctx' => $contesto],
                        'keep_alive' => '10m',
                        'messages' => [['role' => 'system', 'content' => $l['istruzioni']], ['role' => 'user', 'content' => $l['prompt']]],
                    ]])->toArray();
                } catch (\Throwable) {
                    $api('POST', "/lavori/{$l['id']}/errore", ['codice' => 'timeout'])->getStatusCode();
                    $io->writeln(sprintf('  %s: errore di Ollama, rimandato', $l['documento']));
                    continue;
                }
                $ms = (int) round((microtime(true) - $inizio) * 1000);
                $esito = $api('POST', "/lavori/{$l['id']}/risultato", [
                    'risposta' => (string) ($r['message']['content'] ?? ''),
                    'modello' => $modello,
                    'durataMs' => $ms,
                    'tokenPrompt' => $r['prompt_eval_count'] ?? null,
                    'tokenRisposta' => $r['eval_count'] ?? null,
                ]);
                $voci = 200 === $esito->getStatusCode() ? $esito->toArray()['voci'] : null;
                ++$fatti;
                $tempi[] = $ms;
                $ultimoLavoro = time();
                $io->writeln(sprintf('  %s (%s): %s in %.1f s', $l['documento'], $l['pratica'], null === $voci ? 'rifiutato ('.$esito->getStatusCode().')' : $voci.' voci', $ms / 1000));
            }
        }
        $io->success([] === $tempi ? 'Nessun lavoro.' : sprintf('%d documenti, %.1f s in media, %.1f s in tutto.', $fatti, array_sum($tempi) / \count($tempi) / 1000, array_sum($tempi) / 1000));

        return Command::SUCCESS;
    }

    private function abbina(SymfonyStyle $io, string $server, string $nome): ?string
    {
        $oauth = $server.'/api/ai-connector-plugin/oauth/';
        $d = $this->http->request('POST', $oauth.'device', ['json' => ['client_id' => 'cartellaviva-mac', 'nome' => $nome]])->toArray();
        $io->section('Approva nell\'app: Sicurezza e accesso → AI sul tuo Mac');
        $io->writeln(sprintf('Codice: <info>%s</info>  (oppure %s)', $d['user_code'], $d['verification_uri_complete']));
        $fine = time() + (int) $d['expires_in'];
        $intervallo = (int) $d['interval'];
        while (time() < $fine) {
            sleep($intervallo);
            $t = $this->http->request('POST', $oauth.'token', ['json' => ['client_id' => 'cartellaviva-mac', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $d['device_code']]]);
            $corpo = $t->toArray(false);
            if (200 === $t->getStatusCode()) {
                $io->writeln('Approvato.');

                return $corpo['access_token'];
            }
            match ($corpo['error'] ?? '') {
                'authorization_pending' => null,
                'slow_down' => $intervallo += 5,
                default => $fine = 0,
            };
            if (0 === $fine) {
                $io->error('Abbinamento non riuscito: '.($corpo['error_description'] ?? $corpo['error'] ?? '?'));
            }
        }

        return null;
    }
}
