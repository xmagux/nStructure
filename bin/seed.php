<?php

declare(strict_types=1);

use NStructure\Infrastructure\Database\ConnectionFactory;
use NStructure\Infrastructure\Database\SqlFileRunner;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);
if (is_file($rootPath . '/.env')) {
    (new Dotenv())->usePutenv()->loadEnv($rootPath . '/.env');
}

$settingsFactory = require $rootPath . '/config/settings.php';
$settings = $settingsFactory($rootPath);
$pdo = ConnectionFactory::create($settings['database']);

$pdo->beginTransaction();
try {
    SqlFileRunner::run($pdo, $rootPath . '/database/seed.sql');
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}
fwrite(STDOUT, "Sample data loaded.\n");
