<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Database di test ricreato a ogni esecuzione applicando le migrazioni, così vengono verificate anche loro.
if ('test' === $_SERVER['APP_ENV']) {
    $dataDir = dirname(__DIR__).'/var/data';
    @mkdir($dataDir, 0775, true);
    foreach (glob($dataDir.'/test.db*') as $file) {
        unlink($file);
    }
    passthru(sprintf('php %s/bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration --quiet', escapeshellarg(dirname(__DIR__))), $exitCode);
    if (0 !== $exitCode) {
        exit($exitCode);
    }
}
