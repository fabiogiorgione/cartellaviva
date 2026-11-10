<?php

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// DB di test ricreato a ogni esecuzione con le migration reali (trigger inclusi)
// e con catalogo permessi + ruoli di sistema. Ogni test poi gira in una
// transazione annullata (DAMA), quindi questi dati restano puliti.
(static function (): void {
    $kernel = new Kernel('test', false);
    $kernel->boot();
    $application = new Application($kernel);
    $application->setAutoExit(false);
    $output = new ConsoleOutput(ConsoleOutput::VERBOSITY_QUIET);

    foreach ([
        ['command' => 'doctrine:database:drop', '--force' => true, '--if-exists' => true],
        ['command' => 'doctrine:database:create'],
        ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true],
        ['command' => 'app:permissions:sync'],
        ['command' => 'app:roles:sync'],
        ['command' => 'app:geo:import'],
        ['command' => 'app:categorie:sync'],
    ] as $input) {
        $arrayInput = new ArrayInput($input);
        $arrayInput->setInteractive(false);
        if (0 !== $application->run($arrayInput, $output)) {
            throw new RuntimeException('Setup DB di test fallito: '.$input['command']);
        }
    }
    $kernel->shutdown();
})();
