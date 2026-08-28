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
$pingDefaultIntervalSeconds = max(1, (int) $env('SENSOR_DAEMON_PING_DEFAULT_INTERVAL', 5));
$pingFastIntervalSeconds = max(1, (int) $env('SENSOR_DAEMON_PING_FAST_INTERVAL', 2));
$keepaliveSeconds = max(1, (int) $env('SENSOR_DAEMON_KEEPALIVE_SECONDS', 3600));
$watchedKeepaliveSeconds = max(1, (int) $env('SENSOR_DAEMON_WATCHED_KEEPALIVE_SECONDS', 5));
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
 *     humidity_divisor: float, ping_enabled: bool, name: string,
 *     channels: array<int, array{id: int, label: string, channel_type: string, value_oid: string, value_divisor: float}>}>
 */
$loadSensors = static function () use ($pdo): array {
    $statement = $pdo->query(
        'SELECT id, name, host, snmp_port, snmp_community,
            temperature_oid, temperature_divisor, humidity_oid, humidity_divisor, ping_enabled
         FROM environmental_sensors WHERE archived_at IS NULL AND monitoring_enabled = 1',
    );
    $sensors = array_map(static fn (array $row): array => [
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
        'channels' => [],
    ], $statement->fetchAll());
    $byId = [];
    foreach ($sensors as &$sensor) {
        $byId[$sensor['id']] = &$sensor;
    }
    unset($sensor);

    if ($byId !== []) {
        $channelStatement = $pdo->query(
            'SELECT sensor_id, id, label, channel_type, value_oid, value_divisor FROM environmental_sensor_channels',
        );
        foreach ($channelStatement->fetchAll() as $row) {
            $sensorId = (int) $row['sensor_id'];
            if (!isset($byId[$sensorId])) {
                continue;
            }
            $byId[$sensorId]['channels'][] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'channel_type' => (string) $row['channel_type'],
                'value_oid' => (string) $row['value_oid'],
                'value_divisor' => (float) $row['value_divisor'],
            ];
        }
    }

    return $sensors;
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

$nextSnmpDueAt = [];
$nextPingDueAt = [];
$previouslyActiveSensorIds = [];
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

    // Ping and SNMP are scheduled independently: ping is cheap (one batched
    // fping call covers every due host) and worth checking much more often
    // than temperature/humidity, which change slowly by nature.
    $activeSensorIds = $heartbeatStore->activeSensorIds();
    $snmpDueSensors = [];
    $pingDueSensors = [];
    foreach ($sensors as $sensor) {
        $isActive = in_array($sensor['id'], $activeSensorIds, true);
        // A sensor that just became watched shouldn't wait out whatever
        // slow-cadence due time was already scheduled from before — jump
        // the queue so its chart starts filling in immediately.
        if ($isActive && !in_array($sensor['id'], $previouslyActiveSensorIds, true)) {
            $nextSnmpDueAt[$sensor['id']] = 0;
            $nextPingDueAt[$sensor['id']] = 0;
        }

        $snmpInterval = $isActive ? $fastIntervalSeconds : $defaultIntervalSeconds;
        if (($nextSnmpDueAt[$sensor['id']] ?? 0) <= $now) {
            $snmpDueSensors[] = $sensor;
            $nextSnmpDueAt[$sensor['id']] = $now + $snmpInterval;
        }

        if ($sensor['ping_enabled']) {
            $pingInterval = $isActive ? $pingFastIntervalSeconds : $pingDefaultIntervalSeconds;
            if (($nextPingDueAt[$sensor['id']] ?? 0) <= $now) {
                $pingDueSensors[] = $sensor;
                $nextPingDueAt[$sensor['id']] = $now + $pingInterval;
            }
        }
    }
    $previouslyActiveSensorIds = $activeSensorIds;

    if ($snmpDueSensors !== [] || $pingDueSensors !== []) {
        $builder = new InfluxLineProtocolBuilder();
        $timestampMs = (int) round(microtime(true) * 1000);

        if ($pingDueSensors !== []) {
            $pingHosts = array_column($pingDueSensors, 'host');
            $pingResults = $fping->pingBatch($pingHosts, $pingTimeoutMs);

            foreach ($pingDueSensors as $sensor) {
                $tags = ['sensor_id' => (string) $sensor['id'], 'sensor' => $sensor['name']];
                $keepalive = in_array($sensor['id'], $activeSensorIds, true) ? $watchedKeepaliveSeconds : $keepaliveSeconds;
                $ping = $pingResults[$sensor['host']] ?? ['ok' => false, 'latency_ms' => null];
                $upValue = $ping['ok'] ? 1.0 : 0.0;
                $upKey = 'ping_up:' . $sensor['id'];
                if ($seriesState->shouldSend($upKey, $upValue, 0.0, $keepalive, $now)) {
                    $builder->addPoint('sensor_ping_up', $tags, $upValue, $timestampMs);
                    $seriesState->recordSent($upKey, $upValue, $now);
                }
                if ($ping['ok'] && $ping['latency_ms'] !== null) {
                    $latencyKey = 'ping_latency:' . $sensor['id'];
                    if ($seriesState->shouldSend($latencyKey, $ping['latency_ms'], $latencyHysteresis, $keepalive, $now)) {
                        $builder->addPoint('sensor_ping_latency_ms', $tags, $ping['latency_ms'], $timestampMs);
                        $seriesState->recordSent($latencyKey, $ping['latency_ms'], $now);
                    }
                }
            }
        }

        foreach ($snmpDueSensors as $sensor) {
            $tags = ['sensor_id' => (string) $sensor['id'], 'sensor' => $sensor['name']];
            $keepalive = in_array($sensor['id'], $activeSensorIds, true) ? $watchedKeepaliveSeconds : $keepaliveSeconds;

            if ($sensor['temperature_oid'] !== null && $sensor['temperature_oid'] !== '') {
                $result = $snmp->get($sensor['host'], $sensor['snmp_port'], $sensor['snmp_community'], $sensor['temperature_oid'], $snmpTimeoutMicroseconds, $snmpRetries);
                $probeUp = $result['ok'] ? 1.0 : 0.0;
                $probeKey = 'temp_probe:' . $sensor['id'];
                if ($seriesState->shouldSend($probeKey, $probeUp, 0.0, $keepalive, $now)) {
                    $builder->addPoint('sensor_temperature_probe_up', $tags, $probeUp, $timestampMs);
                    $seriesState->recordSent($probeKey, $probeUp, $now);
                }
                if ($result['ok']) {
                    $value = $scaleValue((string) $result['value'], $sensor['temperature_divisor']);
                    if ($value !== null) {
                        $valueKey = 'temp:' . $sensor['id'];
                        if ($seriesState->shouldSend($valueKey, $value, $temperatureHysteresis, $keepalive, $now)) {
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
                if ($seriesState->shouldSend($probeKey, $probeUp, 0.0, $keepalive, $now)) {
                    $builder->addPoint('sensor_humidity_probe_up', $tags, $probeUp, $timestampMs);
                    $seriesState->recordSent($probeKey, $probeUp, $now);
                }
                if ($result['ok']) {
                    $value = $scaleValue((string) $result['value'], $sensor['humidity_divisor']);
                    if ($value !== null) {
                        $valueKey = 'humidity:' . $sensor['id'];
                        if ($seriesState->shouldSend($valueKey, $value, $humidityHysteresis, $keepalive, $now)) {
                            $builder->addPoint('sensor_humidity_percent', $tags, $value, $timestampMs);
                            $seriesState->recordSent($valueKey, $value, $now);
                        }
                    }
                }
            }

            foreach ($sensor['channels'] as $channel) {
                $result = $snmp->get($sensor['host'], $sensor['snmp_port'], $sensor['snmp_community'], $channel['value_oid'], $snmpTimeoutMicroseconds, $snmpRetries);
                if (!$result['ok']) {
                    continue;
                }
                $value = $scaleValue((string) $result['value'], $channel['value_divisor']);
                if ($value === null) {
                    continue;
                }
                $channelKey = 'channel:' . $channel['id'];
                $hysteresis = $channel['channel_type'] === 'humidity' ? $humidityHysteresis : $temperatureHysteresis;
                if ($seriesState->shouldSend($channelKey, $value, $hysteresis, $keepalive, $now)) {
                    $channelTags = $tags + ['channel_id' => (string) $channel['id'], 'channel' => $channel['label'], 'channel_type' => $channel['channel_type']];
                    $builder->addPoint('sensor_channel_value', $channelTags, $value, $timestampMs);
                    $seriesState->recordSent($channelKey, $value, $now);
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
