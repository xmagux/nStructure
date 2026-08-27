<?php

declare(strict_types=1);

/**
 * Standalone collector for the environmental sensors module. Not run via
 * cron: this process loops forever (until $maxIterations or a signal),
 * ticking once a second with drift-free sleep, checking each configured
 * sensor's own due time, and batching ICMP (fping) + SNMP (snmp2_get)
 * checks into VictoriaMetrics writes. See docs/DEPLOYMENT.md for the
 * systemd unit and package requirements (php-snmp, fping).
 *
 * Usage: php bin/sensors-daemon.php
 * All tuning is via environment variables — see .env.example.
 */

use NStructure\Infrastructure\Database\ConnectionFactory;
use NStructure\Infrastructure\Heartbeat\HeartbeatStore;
use NStructure\Infrastructure\Metrics\InfluxLineProtocolBuilder;
use NStructure\Infrastructure\Metrics\MetricsBuffer;
use NStructure\Infrastructure\Metrics\SeriesStateTracker;
use NStructure\Infrastructure\Metrics\VictoriaMetricsClient;
use NStructure\Infrastructure\Network\FpingClient;
use NStructure\Infrastructure\Snmp\Snmp2Client;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);
if (is_file($rootPath . '/.env')) {
    (new Dotenv())->usePutenv()->loadEnv($rootPath . '/.env');
}

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? getenv($key) ?: $default;

$maxIterations = max(1, (int) $env('SENSOR_DAEMON_MAX_ITERATIONS', 1000));
$defaultIntervalSeconds = max(1, (int) $env('SENSOR_DAEMON_DEFAULT_INTERVAL', 300));
$fastIntervalSeconds = max(1, (int) $env('SENSOR_DAEMON_FAST_INTERVAL', 5));
$keepaliveSeconds = max(1, (int) $env('SENSOR_DAEMON_KEEPALIVE_SECONDS', 3600));
$temperatureHysteresis = (float) $env('SENSOR_DAEMON_TEMP_HYSTERESIS', 0.2);
$humidityHysteresis = (float) $env('SENSOR_DAEMON_HUMIDITY_HYSTERESIS', 0.5);
$latencyHysteresis = (float) $env('SENSOR_DAEMON_LATENCY_HYSTERESIS', 1.0);
$bufferMaxLines = max(100, (int) $env('SENSOR_DAEMON_BUFFER_MAX_LINES', 5000));
$lockFilePath = (string) $env('SENSOR_DAEMON_LOCK_FILE', sys_get_temp_dir() . '/nstructure-sensors-daemon.lock');
$snmpTimeoutMicroseconds = max(100_000, (int) $env('SENSOR_DAEMON_SNMP_TIMEOUT_US', 1_000_000));
$snmpRetries = max(0, (int) $env('SENSOR_DAEMON_SNMP_RETRIES', 1));
$pingTimeoutMs = max(100, (int) $env('SENSOR_DAEMON_PING_TIMEOUT_MS', 1000));

$lockHandle = fopen($lockFilePath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another sensors-daemon instance is already running (lock: $lockFilePath).\n");
    exit(1);
}

$settingsFactory = require $rootPath . '/config/settings.php';
$settings = $settingsFactory($rootPath);
$pdo = ConnectionFactory::create($settings['database']);
$metricsClient = new VictoriaMetricsClient($settings['metrics']['victoriametrics_url']);
$heartbeatStore = new HeartbeatStore($settings['metrics']['heartbeat_file']);
$fping = new FpingClient();
$snmp = new Snmp2Client();
$seriesState = new SeriesStateTracker();
$buffer = new MetricsBuffer($bufferMaxLines);

$shouldExit = false;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $signalHandler = static function (int $signal) use (&$shouldExit): void {
        $shouldExit = true;
    };
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGINT, $signalHandler);
}

/**
 * @return array<int, array{id: int, host: string, snmp_port: int, snmp_community: string,
 *     temperature_oid: ?string, temperature_divisor: float, humidity_oid: ?string,
 *     humidity_divisor: float, ping_enabled: bool, name: string}>
 */
$loadSensors = static function () use ($pdo): array {
    $statement = $pdo->query(
        'SELECT id, name, host, snmp_port, snmp_community,
            temperature_oid, temperature_divisor, humidity_oid, humidity_divisor, ping_enabled
         FROM environmental_sensors WHERE archived_at IS NULL',
    );
    return array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'host' => (string) $row['host'],
        'snmp_port' => (int) $row['snmp_port'],
        'snmp_community' => (string) $row['snmp_community'],
        'temperature_oid' => $row['temperature_oid'],
        'temperature_divisor' => (float) $row['temperature_divisor'],
        'humidity_oid' => $row['humidity_oid'],
        'humidity_divisor' => (float) $row['humidity_divisor'],
        'ping_enabled' => (bool) $row['ping_enabled'],
    ], $statement->fetchAll());
};

$scaleValue = static function (string $raw, float $divisor): ?float {
    if (preg_match('/-?\d+(?:\.\d+)?/', $raw, $matches) !== 1) {
        return null;
    }
    $numeric = (float) $matches[0];
    if (!str_contains($matches[0], '.') && $divisor > 0) {
        $numeric /= $divisor;
    }
    return $numeric;
};

fwrite(STDOUT, sprintf("[%s] sensors-daemon started (pid %d, max %d iterations)\n", date('c'), getmypid(), $maxIterations));

$nextDueAt = [];
$iteration = 0;
$sensorsReloadedAt = 0;
$sensors = [];

while ($iteration < $maxIterations && !$shouldExit) {
    $tickStartedAt = microtime(true);
    $now = time();

    if ($now - $sensorsReloadedAt >= 60 || $sensors === []) {
        $sensors = $loadSensors();
        $sensorsReloadedAt = $now;
    }

    $activeSensorIds = $heartbeatStore->activeSensorIds();
    $dueSensors = [];
    foreach ($sensors as $sensor) {
        $interval = in_array($sensor['id'], $activeSensorIds, true) ? $fastIntervalSeconds : $defaultIntervalSeconds;
        if (($nextDueAt[$sensor['id']] ?? 0) <= $now) {
            $dueSensors[] = $sensor;
            $nextDueAt[$sensor['id']] = $now + $interval;
        }
    }

    if ($dueSensors !== []) {
        $pingHosts = array_column(array_filter($dueSensors, static fn (array $s): bool => $s['ping_enabled']), 'host');
        $pingResults = $pingHosts !== [] ? $fping->pingBatch($pingHosts, $pingTimeoutMs) : [];

        $builder = new InfluxLineProtocolBuilder();
        $timestampMs = (int) round(microtime(true) * 1000);

        foreach ($dueSensors as $sensor) {
            $tags = ['sensor_id' => (string) $sensor['id'], 'sensor' => $sensor['name']];

            if ($sensor['temperature_oid'] !== null && $sensor['temperature_oid'] !== '') {
                $result = $snmp->get($sensor['host'], $sensor['snmp_port'], $sensor['snmp_community'], $sensor['temperature_oid'], $snmpTimeoutMicroseconds, $snmpRetries);
                $probeUp = $result['ok'] ? 1.0 : 0.0;
                $probeKey = 'temp_probe:' . $sensor['id'];
                if ($seriesState->shouldSend($probeKey, $probeUp, 0.0, $keepaliveSeconds, $now)) {
                    $builder->addPoint('sensor_temperature_probe_up', $tags, $probeUp, $timestampMs);
                    $seriesState->recordSent($probeKey, $probeUp, $now);
                }
                if ($result['ok']) {
                    $value = $scaleValue((string) $result['value'], $sensor['temperature_divisor']);
                    if ($value !== null) {
                        $valueKey = 'temp:' . $sensor['id'];
                        if ($seriesState->shouldSend($valueKey, $value, $temperatureHysteresis, $keepaliveSeconds, $now)) {
                            $builder->addPoint('sensor_temperature_celsius', $tags, $value, $timestampMs);
                            $seriesState->recordSent($valueKey, $value, $now);
                        }
                    }
                }
            }

            if ($sensor['humidity_oid'] !== null && $sensor['humidity_oid'] !== '') {
                $result = $snmp->get($sensor['host'], $sensor['snmp_port'], $sensor['snmp_community'], $sensor['humidity_oid'], $snmpTimeoutMicroseconds, $snmpRetries);
                $probeUp = $result['ok'] ? 1.0 : 0.0;
                $probeKey = 'humidity_probe:' . $sensor['id'];
                if ($seriesState->shouldSend($probeKey, $probeUp, 0.0, $keepaliveSeconds, $now)) {
                    $builder->addPoint('sensor_humidity_probe_up', $tags, $probeUp, $timestampMs);
                    $seriesState->recordSent($probeKey, $probeUp, $now);
                }
                if ($result['ok']) {
                    $value = $scaleValue((string) $result['value'], $sensor['humidity_divisor']);
                    if ($value !== null) {
                        $valueKey = 'humidity:' . $sensor['id'];
                        if ($seriesState->shouldSend($valueKey, $value, $humidityHysteresis, $keepaliveSeconds, $now)) {
                            $builder->addPoint('sensor_humidity_percent', $tags, $value, $timestampMs);
                            $seriesState->recordSent($valueKey, $value, $now);
                        }
                    }
                }
            }

            if ($sensor['ping_enabled']) {
                $ping = $pingResults[$sensor['host']] ?? ['ok' => false, 'latency_ms' => null];
                $upValue = $ping['ok'] ? 1.0 : 0.0;
                $upKey = 'ping_up:' . $sensor['id'];
                if ($seriesState->shouldSend($upKey, $upValue, 0.0, $keepaliveSeconds, $now)) {
                    $builder->addPoint('sensor_ping_up', $tags, $upValue, $timestampMs);
                    $seriesState->recordSent($upKey, $upValue, $now);
                }
                if ($ping['ok'] && $ping['latency_ms'] !== null) {
                    $latencyKey = 'ping_latency:' . $sensor['id'];
                    if ($seriesState->shouldSend($latencyKey, $ping['latency_ms'], $latencyHysteresis, $keepaliveSeconds, $now)) {
                        $builder->addPoint('sensor_ping_latency_ms', $tags, $ping['latency_ms'], $timestampMs);
                        $seriesState->recordSent($latencyKey, $ping['latency_ms'], $now);
                    }
                }
            }
        }

        if (!$builder->isEmpty()) {
            $buffer->add($builder->build());
        }
    }

    if ($buffer->count() > 0) {
        $flushed = $buffer->flush($metricsClient);
        if (!$flushed) {
            fwrite(STDERR, sprintf("[%s] VictoriaMetrics unreachable, %d point(s) buffered for retry.\n", date('c'), $buffer->count()));
        }
    }

    $iteration++;
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    if ($shouldExit) {
        break;
    }

    $elapsed = microtime(true) - $tickStartedAt;
    $sleepSeconds = 1.0 - $elapsed;
    if ($sleepSeconds > 0) {
        usleep((int) ($sleepSeconds * 1_000_000));
    }
}

$buffer->flush($metricsClient);
fwrite(STDOUT, sprintf("[%s] sensors-daemon exiting after %d iteration(s)%s.\n", date('c'), $iteration, $shouldExit ? ' (signal received)' : ''));

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);
