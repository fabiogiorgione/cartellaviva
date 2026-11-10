<?php

// Per PHPStan (phpstan-doctrine): l'EntityManager del kernel di dev, per
// controllare query DQL e tipi delle entità. Non apre connessioni da solo.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
