<?php

declare(strict_types=1);

use NStructure\Infrastructure\Database\ConnectionFactory;
use NStructure\Infrastructure\Repository\MySqlSensorRepository;
use NStructure\Infrastructure\Snmp\SnmpClient;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);
if (is_file($rootPath . '/.env')) {
    (new Dotenv())->usePutenv()->loadEnv($rootPath . '/.env');
}

$settingsFactory = require $rootPath . '/config/settings.php';
$settings = $settingsFactory($rootPath);
$pdo = ConnectionFactory::create($settings['database']);
$repository = new MySqlSensorRepository($pdo, new SnmpClient());

$readings = $repository->pollAll();
foreach ($readings as $sensor) {
    $temperature = $sensor['temperature']['ok'] ? $sensor['temperature']['value'] . ' (' . $sensor['temperature']['raw'] . ')' : ($sensor['temperature']['error'] ?? 'n/a');
    $humidity = $sensor['humidity']['ok'] ? $sensor['humidity']['value'] . ' (' . $sensor['humidity']['raw'] . ')' : ($sensor['humidity']['error'] ?? 'n/a');
    $ping = $sensor['ping'] === null ? 'disabled' : ($sensor['ping']['ok'] ? 'up' : 'down');
    fwrite(STDOUT, sprintf(
        "[%s] temp=%s humidity=%s ping=%s\n",
        $sensor['name'],
        $temperature,
        $humidity,
        $ping,
    ));
}

fwrite(STDOUT, sprintf("Polled %d sensor(s).\n", count($readings)));
