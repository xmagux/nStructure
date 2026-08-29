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
use NStructure\Infrastructure\Mail\Mailer;
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
// Sensors with digital dry-contact inputs (grid/generator power presence)
// are alarm-relevant on a much shorter fuse than ordinary temperature
// drift, so they get checked (and, if needed, emailed) on this interval
// instead of the slow default one above.
$alarmIntervalSeconds = max(1, (int) $env('SENSOR_DAEMON_ALARM_INTERVAL', 30));

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
$mailer = new Mailer($settings['mail']);
$appUrl = rtrim((string) $settings['app']['url'], '/');

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
 *     humidity_divisor: float, temperature_min: ?float, temperature_max: ?float,
 *     humidity_min: ?float, humidity_max: ?float, ping_enabled: bool, name: string,
 *     channels: array<int, array{id: int, label: string, channel_type: string, value_oid: string, value_divisor: float}>,
 *     inputs: array<int, array{id: int, group: ?string, alarm_state_oid: string}>}>
 */
$loadSensors = static function () use ($pdo): array {
    $statement = $pdo->query(
        'SELECT id, name, host, snmp_port, snmp_community,
            temperature_oid, temperature_divisor, humidity_oid, humidity_divisor,
            temperature_min, temperature_max, humidity_min, humidity_max, ping_enabled
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
        'temperature_min' => $row['temperature_min'] !== null ? (float) $row['temperature_min'] : null,
        'temperature_max' => $row['temperature_max'] !== null ? (float) $row['temperature_max'] : null,
        'humidity_min' => $row['humidity_min'] !== null ? (float) $row['humidity_min'] : null,
        'humidity_max' => $row['humidity_max'] !== null ? (float) $row['humidity_max'] : null,
        'ping_enabled' => (bool) $row['ping_enabled'],
        'channels' => [],
        'inputs' => [],
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

        $inputStatement = $pdo->query(
            'SELECT sensor_id, id, group_name, alarm_state_oid FROM environmental_sensor_inputs',
        );
        foreach ($inputStatement->fetchAll() as $row) {
            $sensorId = (int) $row['sensor_id'];
            if (!isset($byId[$sensorId])) {
                continue;
            }
            $byId[$sensorId]['inputs'][] = [
                'id' => (int) $row['id'],
                'group' => $row['group_name'],
                'alarm_state_oid' => (string) $row['alarm_state_oid'],
            ];
        }
    }

    return $sensors;
};

$loadAlertSettings = static function () use ($pdo): array {
    $value = $pdo->query('SELECT repeat_interval_minutes FROM alert_settings WHERE id = 1')->fetchColumn();
    return ['repeat_interval_minutes' => $value !== false ? (int) $value : 60];
};

/**
 * Resolves, per sensor, the deduplicated list of active recipient emails
 * reachable either through a direct assignment or through any group the
 * sensor is assigned to — a recipient in more than one assigned group (or
 * both directly and via a group) only gets one email.
 *
 * @return array<int, array<int, array{email: string, name: ?string}>>
 */
$loadSensorRecipients = static function () use ($pdo): array {
    $statement = $pdo->query(
        "SELECT sat.sensor_id, r.id AS recipient_id, r.email, r.name
         FROM sensor_alert_targets sat
         JOIN alert_recipients r ON r.id = sat.target_id AND sat.target_type = 'recipient'
         WHERE r.archived_at IS NULL
         UNION
         SELECT sat.sensor_id, r.id AS recipient_id, r.email, r.name
         FROM sensor_alert_targets sat
         JOIN alert_group_members agm ON agm.group_id = sat.target_id AND sat.target_type = 'group'
         JOIN alert_recipients r ON r.id = agm.recipient_id
         WHERE r.archived_at IS NULL",
    );
    $bySensor = [];
    foreach ($statement->fetchAll() as $row) {
        $bySensor[(int) $row['sensor_id']][(int) $row['recipient_id']] = ['email' => $row['email'], 'name' => $row['name']];
    }
    return array_map('array_values', $bySensor);
};

$reasonLabels = [
    'ping' => 'brak połączenia (ping)',
    'temperature' => 'temperatura poza zakresem',
    'humidity' => 'wilgotność poza zakresem',
    'inputs' => 'zanik zasilania (MIASTO)',
];
$describeReasons = static fn (array $reasons): string => implode(', ', array_map(
    static fn (string $reason): string => $reasonLabels[$reason] ?? $reason,
    $reasons,
));

/**
 * The email side of alarm handling: on the transition into alarm, mails
 * immediately; while it stays active, re-mails only once the configured
 * repeat interval has elapsed; on the transition back to normal, sends a
 * single all-clear mail. Sensors nobody has assigned an email to simply
 * update sensor_alert_state without sending anything.
 */
$evaluateAlert = static function (array $sensor, bool $isAlarm, array $reasons) use ($pdo, $mailer, $appUrl, $describeReasons, &$alertSettings, &$recipientsBySensor): void {
    $stateStatement = $pdo->prepare('SELECT is_active, last_notified_at FROM sensor_alert_state WHERE sensor_id = :id');
    $stateStatement->execute(['id' => $sensor['id']]);
    $state = $stateStatement->fetch();
    $wasActive = $state !== false && (bool) $state['is_active'];
    $reasonsText = implode(',', $reasons);
    $recipients = $recipientsBySensor[$sensor['id']] ?? [];

    if ($isAlarm && !$wasActive) {
        $pdo->prepare(
            'INSERT INTO sensor_alert_state (sensor_id, is_active, reasons, started_at, last_notified_at)
             VALUES (:id, 1, :reasons, NOW(), NOW())
             ON DUPLICATE KEY UPDATE is_active = 1, reasons = VALUES(reasons), started_at = NOW(), last_notified_at = NOW()',
        )->execute(['id' => $sensor['id'], 'reasons' => $reasonsText]);
        $subject = sprintf('[nStructure] ALARM: %s', $sensor['name']);
        $body = sprintf(
            "Czujnik: %s\nHost: %s\nPowód: %s\nCzas wystąpienia: %s\n\nSzczegóły: %s/tools/sensors\n",
            $sensor['name'],
            $sensor['host'],
            $describeReasons($reasons),
            date('Y-m-d H:i:s'),
            $appUrl,
        );
        foreach ($recipients as $recipient) {
            $mailer->send($recipient['email'], $recipient['name'], $subject, $body);
        }
        return;
    }

    if ($isAlarm && $wasActive) {
        $lastNotifiedAt = $state['last_notified_at'] !== null ? strtotime((string) $state['last_notified_at']) : 0;
        $repeatSeconds = max(60, $alertSettings['repeat_interval_minutes'] * 60);
        if (time() - $lastNotifiedAt < $repeatSeconds) {
            return;
        }
        $pdo->prepare('UPDATE sensor_alert_state SET reasons = :reasons, last_notified_at = NOW() WHERE sensor_id = :id')
            ->execute(['id' => $sensor['id'], 'reasons' => $reasonsText]);
        $subject = sprintf('[nStructure] Alarm trwa: %s', $sensor['name']);
        $body = sprintf(
            "Czujnik: %s\nHost: %s\nPowód: %s\nAlarm nadal aktywny (przypomnienie).\n\nSzczegóły: %s/tools/sensors\n",
            $sensor['name'],
            $sensor['host'],
            $describeReasons($reasons),
            $appUrl,
        );
        foreach ($recipients as $recipient) {
            $mailer->send($recipient['email'], $recipient['name'], $subject, $body);
        }
        return;
    }

    if (!$isAlarm && $wasActive) {
        $pdo->prepare('UPDATE sensor_alert_state SET is_active = 0, reasons = NULL WHERE sensor_id = :id')
            ->execute(['id' => $sensor['id']]);
        $subject = sprintf('[nStructure] Alarm zakończony: %s', $sensor['name']);
        $body = sprintf(
            "Czujnik: %s\nHost: %s\nSytuacja wróciła do normy o %s.\n\nSzczegóły: %s/tools/sensors\n",
            $sensor['name'],
            $sensor['host'],
            date('Y-m-d H:i:s'),
            $appUrl,
        );
        foreach ($recipients as $recipient) {
            $mailer->send($recipient['email'], $recipient['name'], $subject, $body);
        }
    }
};

$isOutOfRange = static fn (float $value, ?float $min, ?float $max): bool => ($min !== null && $value < $min) || ($max !== null && $value > $max);

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
$lastPingOk = [];
$iteration = 0;
$sensorsReloadedAt = 0;
$sensors = [];
$alertSettings = $loadAlertSettings();
$recipientsBySensor = $loadSensorRecipients();

while ($iteration < $maxIterations && !$shouldExit) {
    $tickStartedAt = microtime(true);
    $now = time();

    if ($now - $sensorsReloadedAt >= 60 || $sensors === []) {
        $sensors = $loadSensors();
        $alertSettings = $loadAlertSettings();
        $recipientsBySensor = $loadSensorRecipients();
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

        // Sensors with digital dry-contact inputs (grid/generator power
        // presence) get checked much more often than plain temperature —
        // an undetected power loss for minutes defeats the point of an
        // email alarm.
        $defaultSnmpInterval = $sensor['inputs'] !== [] ? $alarmIntervalSeconds : $defaultIntervalSeconds;
        $snmpInterval = $isActive ? $fastIntervalSeconds : $defaultSnmpInterval;
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
                $lastPingOk[$sensor['id']] = $ping['ok'];
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
            $temperatureValue = null;
            $humidityValue = null;

            if ($sensor['temperature_oid'] !== null && $sensor['temperature_oid'] !== '') {
                $result = $snmp->get($sensor['host'], $sensor['snmp_port'], $sensor['snmp_community'], $sensor['temperature_oid'], $snmpTimeoutMicroseconds, $snmpRetries);
                $probeUp = $result['ok'] ? 1.0 : 0.0;
                $probeKey = 'temp_probe:' . $sensor['id'];
                if ($seriesState->shouldSend($probeKey, $probeUp, 0.0, $keepalive, $now)) {
                    $builder->addPoint('sensor_temperature_probe_up', $tags, $probeUp, $timestampMs);
                    $seriesState->recordSent($probeKey, $probeUp, $now);
                }
                if ($result['ok']) {
                    $temperatureValue = $scaleValue((string) $result['value'], $sensor['temperature_divisor']);
                    if ($temperatureValue !== null) {
                        $valueKey = 'temp:' . $sensor['id'];
                        if ($seriesState->shouldSend($valueKey, $temperatureValue, $temperatureHysteresis, $keepalive, $now)) {
                            $builder->addPoint('sensor_temperature_celsius', $tags, $temperatureValue, $timestampMs);
                            $seriesState->recordSent($valueKey, $temperatureValue, $now);
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
                    $humidityValue = $scaleValue((string) $result['value'], $sensor['humidity_divisor']);
                    if ($humidityValue !== null) {
                        $valueKey = 'humidity:' . $sensor['id'];
                        if ($seriesState->shouldSend($valueKey, $humidityValue, $humidityHysteresis, $keepalive, $now)) {
                            $builder->addPoint('sensor_humidity_percent', $tags, $humidityValue, $timestampMs);
                            $seriesState->recordSent($valueKey, $humidityValue, $now);
                        }
                    }
                }
            }

            $temperatureBreach = $temperatureValue !== null && $isOutOfRange($temperatureValue, $sensor['temperature_min'], $sensor['temperature_max']);
            $humidityBreach = $humidityValue !== null && $isOutOfRange($humidityValue, $sensor['humidity_min'], $sensor['humidity_max']);

            foreach ($sensor['channels'] as $channel) {
                $result = $snmp->get($sensor['host'], $sensor['snmp_port'], $sensor['snmp_community'], $channel['value_oid'], $snmpTimeoutMicroseconds, $snmpRetries);
                if (!$result['ok']) {
                    continue;
                }
                $value = $scaleValue((string) $result['value'], $channel['value_divisor']);
                if ($value === null) {
                    continue;
                }
                if ($channel['channel_type'] === 'temperature' && $isOutOfRange($value, $sensor['temperature_min'], $sensor['temperature_max'])) {
                    $temperatureBreach = true;
                }
                if ($channel['channel_type'] === 'humidity' && $isOutOfRange($value, $sensor['humidity_min'], $sensor['humidity_max'])) {
                    $humidityBreach = true;
                }
                $channelKey = 'channel:' . $channel['id'];
                $hysteresis = $channel['channel_type'] === 'humidity' ? $humidityHysteresis : $temperatureHysteresis;
                if ($seriesState->shouldSend($channelKey, $value, $hysteresis, $keepalive, $now)) {
                    $channelTags = $tags + ['channel_id' => (string) $channel['id'], 'channel' => $channel['label'], 'channel_type' => $channel['channel_type']];
                    $builder->addPoint('sensor_channel_value', $channelTags, $value, $timestampMs);
                    $seriesState->recordSent($channelKey, $value, $now);
                }
            }

            // Digital dry-contact inputs (grid/generator power presence).
            // The "agregat" (generator) group gets its own state on the web
            // UI but never triggers the email alarm on its own — only a
            // loss of grid ("miasto") power is alarm-worthy, mirroring the
            // web app's own alarm-ring logic in MySqlSensorRepository.
            $inputsBreach = false;
            foreach ($sensor['inputs'] as $input) {
                $result = $snmp->get($sensor['host'], $sensor['snmp_port'], $sensor['snmp_community'], $input['alarm_state_oid'], $snmpTimeoutMicroseconds, $snmpRetries);
                if (!$result['ok']) {
                    continue;
                }
                $state = (int) round((float) $result['value']);
                $pdo->prepare('UPDATE environmental_sensor_inputs SET last_alarm_state = :state, last_read_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute(['id' => $input['id'], 'state' => $state]);
                if ($state === 2 && $input['group'] !== 'agregat') {
                    $inputsBreach = true;
                }
            }

            $reasons = [];
            if ($sensor['ping_enabled'] && ($lastPingOk[$sensor['id']] ?? true) === false) {
                $reasons[] = 'ping';
            }
            if ($temperatureBreach) {
                $reasons[] = 'temperature';
            }
            if ($humidityBreach) {
                $reasons[] = 'humidity';
            }
            if ($inputsBreach) {
                $reasons[] = 'inputs';
            }
            $evaluateAlert($sensor, $reasons !== [], $reasons);
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
