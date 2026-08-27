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

// Cron can't run more often than once a minute, so this script loops on its
// own for just under a minute (pinging every $intervalSeconds) and exits;
// cron simply relaunches it every minute for the next burst.
$intervalSeconds = max(1, (int) ($argv[1] ?? 5));
$durationSeconds = max($intervalSeconds, (int) ($argv[2] ?? 55));

$settingsFactory = require $rootPath . '/config/settings.php';
$settings = $settingsFactory($rootPath);
$pdo = ConnectionFactory::create($settings['database']);
$repository = new MySqlSensorRepository($pdo, new SnmpClient());

$start = time();
$cycles = 0;
while (time() - $start < $durationSeconds) {
    $cycleStart = microtime(true);
    $results = $repository->pingAll();
    $cycles++;
    foreach ($results as $sensor) {
        $latency = $sensor['ping']['latency_ms'] !== null ? sprintf(' (%.1fms)', $sensor['ping']['latency_ms']) : '';
        fwrite(STDOUT, sprintf('[%s] %s: %s%s%s', date('H:i:s'), $sensor['name'], $sensor['ping']['ok'] ? 'up' : 'down', $latency, PHP_EOL));
    }
    $remaining = $intervalSeconds - (microtime(true) - $cycleStart);
    if ($remaining > 0 && time() - $start < $durationSeconds) {
        usleep((int) ($remaining * 1_000_000));
    }
}

fwrite(STDOUT, sprintf('Completed %d ping cycle(s) over ~%ds.%s', $cycles, $durationSeconds, PHP_EOL));
