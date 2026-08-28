<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Repository\SensorRepository;
use NStructure\Infrastructure\Network\FpingClient;
use NStructure\Infrastructure\Snmp\SnmpClient;
use PDO;
use RuntimeException;

final class MySqlSensorRepository implements SensorRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SnmpClient $snmp,
        private readonly FpingClient $fping,
    ) {
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT * FROM environmental_sensors WHERE archived_at IS NULL ORDER BY name',
        );
        return $this->attachChannels($this->attachInputs(array_map($this->normalize(...), $statement->fetchAll())));
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM environmental_sensors WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return $this->attachChannels($this->attachInputs([$this->normalize($row)]))[0];
    }

    /**
     * Attaches each sensor's extra analog channels — devices like the STE2
     * (its generic sensor table can carry any number of probes) that report
     * more than one temperature or humidity reading. The primary
     * temperature_oid/humidity_oid pair stays the "main" reading shown on
     * the tile and used for alarm thresholds; channels are supplemental
     * probes shown alongside it and charted separately.
     */
    private function attachChannels(array $sensors): array
    {
        if ($sensors === []) {
            return $sensors;
        }
        $ids = array_column($sensors, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, sensor_id, position, label, channel_type, value_oid, value_divisor, last_reading, last_read_at
             FROM environmental_sensor_channels WHERE sensor_id IN ($placeholders) ORDER BY sensor_id, position",
        );
        $statement->execute($ids);

        $bySensor = [];
        foreach ($statement->fetchAll() as $row) {
            $bySensor[(int) $row['sensor_id']][] = [
                'id' => (int) $row['id'],
                'position' => (int) $row['position'],
                'label' => $row['label'],
                'channel_type' => $row['channel_type'],
                'value_oid' => $row['value_oid'],
                'value_divisor' => (float) $row['value_divisor'],
                'last_value' => $row['last_reading'] !== null ? (float) $row['last_reading'] : null,
                'last_read_at' => $row['last_read_at'],
            ];
        }

        foreach ($sensors as &$sensor) {
            $sensor['channels'] = $bySensor[$sensor['id']] ?? [];
        }
        unset($sensor);

        return $sensors;
    }

    /**
     * Replaces the full set of extra channels for a sensor in one go —
     * simplest correct behavior for a short, form-edited list: delete
     * whatever was there and re-insert what the form submitted, rather than
     * diffing row by row. Only called when the form actually included a
     * `channels` field, so API callers that omit it leave existing channels
     * untouched.
     */
    private function replaceChannels(int $sensorId, array $channels): void
    {
        $deleteStatement = $this->pdo->prepare('DELETE FROM environmental_sensor_channels WHERE sensor_id = :sensor_id');
        $deleteStatement->execute(['sensor_id' => $sensorId]);
        if ($channels === []) {
            return;
        }
        $insertStatement = $this->pdo->prepare(
            'INSERT INTO environmental_sensor_channels (sensor_id, position, label, channel_type, value_oid, value_divisor)
             VALUES (:sensor_id, :position, :label, :channel_type, :value_oid, :value_divisor)',
        );
        $position = 1;
        foreach ($channels as $channel) {
            $label = trim((string) ($channel['label'] ?? ''));
            $type = (string) ($channel['channel_type'] ?? '');
            $oid = trim((string) ($channel['value_oid'] ?? ''));
            if ($label === '' || $oid === '' || !in_array($type, ['temperature', 'humidity'], true)) {
                continue;
            }
            $divisor = (float) ($channel['value_divisor'] ?? 1);
            $insertStatement->execute([
                'sensor_id' => $sensorId,
                'position' => $position++,
                'label' => $label,
                'channel_type' => $type,
                'value_oid' => $oid,
                'value_divisor' => $divisor > 0 ? $divisor : 1,
            ]);
        }
    }

    /**
     * Attaches each sensor's digital dry-contact inputs (a device like the
     * HWg-PWR, which has none of its own temperature/humidity but reports
     * several labeled alarm-state contacts instead — grid power presence,
     * generator presence, etc.) — empty array for sensors that have none.
     */
    private function attachInputs(array $sensors): array
    {
        if ($sensors === []) {
            return $sensors;
        }
        $ids = array_column($sensors, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, sensor_id, position, label, group_name, alarm_state_oid, last_alarm_state, last_read_at
             FROM environmental_sensor_inputs WHERE sensor_id IN ($placeholders) ORDER BY sensor_id, position",
        );
        $statement->execute($ids);

        $bySensor = [];
        foreach ($statement->fetchAll() as $row) {
            $bySensor[(int) $row['sensor_id']][] = [
                'id' => (int) $row['id'],
                'position' => (int) $row['position'],
                'label' => $row['label'],
                'group' => $row['group_name'],
                'alarm_state_oid' => $row['alarm_state_oid'],
                'last_alarm_state' => $row['last_alarm_state'] !== null ? (int) $row['last_alarm_state'] : null,
                'last_read_at' => $row['last_read_at'],
            ];
        }

        foreach ($sensors as &$sensor) {
            $sensor['inputs'] = $bySensor[$sensor['id']] ?? [];
        }
        unset($sensor);

        return $sensors;
    }

    /**
     * HWg-PWR reports a fixed, well-known set of 8 dry-contact inputs — grid
     * power presence on 3 phases, generator presence on 3 phases, plus 2
     * general-purpose contacts — at a standard OID layout confirmed against
     * a real unit. Picking this model in the form seeds them automatically
     * instead of requiring a manual per-device setup step; only runs once
     * (skipped if the sensor already has inputs), so re-saving an existing
     * HWg-PWR sensor or renaming a different sensor to this model by
     * mistake won't create duplicates or overwrite hand-edited inputs.
     */
    private function seedHwgPwrInputsIfNeeded(int $sensorId, ?string $model): void
    {
        if ($model !== 'HWg-PWR') {
            return;
        }
        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM environmental_sensor_inputs WHERE sensor_id = :sensor_id');
        $countStatement->execute(['sensor_id' => $sensorId]);
        if ((int) $countStatement->fetchColumn() > 0) {
            return;
        }

        $base = '1.3.6.1.4.1.21796.4.6.2.2.1.5'; // inpAlarmState column
        $inputs = [
            ['position' => 1, 'label' => 'L1 MIASTO', 'group' => 'miasto', 'index' => 1],
            ['position' => 2, 'label' => 'L2 MIASTO', 'group' => 'miasto', 'index' => 2],
            ['position' => 3, 'label' => 'L3 MIASTO', 'group' => 'miasto', 'index' => 3],
            ['position' => 4, 'label' => 'L1 AGREGAT', 'group' => 'agregat', 'index' => 4],
            ['position' => 5, 'label' => 'L2 AGREGAT', 'group' => 'agregat', 'index' => 5],
            ['position' => 6, 'label' => 'L3 AGREGAT', 'group' => 'agregat', 'index' => 6],
            ['position' => 7, 'label' => 'Input 7', 'group' => null, 'index' => 7],
            ['position' => 8, 'label' => 'Input 8', 'group' => null, 'index' => 8],
        ];
        $insertStatement = $this->pdo->prepare(
            'INSERT INTO environmental_sensor_inputs (sensor_id, position, label, group_name, alarm_state_oid)
             VALUES (:sensor_id, :position, :label, :group_name, :alarm_state_oid)',
        );
        foreach ($inputs as $input) {
            $insertStatement->execute([
                'sensor_id' => $sensorId,
                'position' => $input['position'],
                'label' => $input['label'],
                'group_name' => $input['group'],
                'alarm_state_oid' => $base . '.' . $input['index'],
            ]);
        }
    }

    public function create(array $input): array
    {
        $record = $this->sensorRecord($input);
        $statement = $this->pdo->prepare(
            'INSERT INTO environmental_sensors (
                name, model, icon, host, snmp_port, snmp_community,
                temperature_oid, temperature_divisor, temperature_min, temperature_max,
                humidity_oid, humidity_divisor, humidity_min, humidity_max,
                ping_enabled, monitoring_enabled, notes
             ) VALUES (
                :name, :model, :icon, :host, :snmp_port, :snmp_community,
                :temperature_oid, :temperature_divisor, :temperature_min, :temperature_max,
                :humidity_oid, :humidity_divisor, :humidity_min, :humidity_max,
                :ping_enabled, :monitoring_enabled, :notes
             )',
        );
        $statement->execute($record);
        $id = (int) $this->pdo->lastInsertId();
        $this->seedHwgPwrInputsIfNeeded($id, $record['model']);
        if (array_key_exists('channels', $input)) {
            $this->replaceChannels($id, $this->decodeChannels($input['channels']));
        }
        $sensor = $this->find($id) ?? throw new RuntimeException('Sensor could not be loaded');
        $this->recordAudit('ENVIRONMENTAL_SENSOR', $id, 'CREATE', $sensor);
        return $sensor;
    }

    public function update(int $id, array $input): array
    {
        $record = $this->sensorRecord($input) + ['id' => $id];
        $statement = $this->pdo->prepare(
            'UPDATE environmental_sensors SET
                name = :name, model = :model, icon = :icon, host = :host, snmp_port = :snmp_port, snmp_community = :snmp_community,
                temperature_oid = :temperature_oid, temperature_divisor = :temperature_divisor,
                temperature_min = :temperature_min, temperature_max = :temperature_max,
                humidity_oid = :humidity_oid, humidity_divisor = :humidity_divisor,
                humidity_min = :humidity_min, humidity_max = :humidity_max,
                ping_enabled = :ping_enabled, monitoring_enabled = :monitoring_enabled, notes = :notes
             WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute($record);
        $this->seedHwgPwrInputsIfNeeded($id, $record['model']);
        if (array_key_exists('channels', $input)) {
            $this->replaceChannels($id, $this->decodeChannels($input['channels']));
        }
        $sensor = $this->find($id);
        if ($sensor === null) {
            throw new RuntimeException('Sensor not found');
        }
        $this->recordAudit('ENVIRONMENTAL_SENSOR', $id, 'UPDATE', $sensor);
        return $sensor;
    }

    /**
     * The generic form-submit helper flattens every field through
     * FormData → Object.fromEntries, which can only carry one value per
     * key — so the channel list crosses the wire as a single JSON-encoded
     * string field rather than a real nested array.
     */
    private function decodeChannels(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function archive(int $id): array
    {
        $sensor = $this->find($id);
        if ($sensor === null) {
            throw new RuntimeException('Sensor not found');
        }
        $statement = $this->pdo->prepare(
            'UPDATE environmental_sensors SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute(['id' => $id]);
        $this->recordAudit('ENVIRONMENTAL_SENSOR', $id, 'ARCHIVE', $sensor);
        return ['id' => $id, 'name' => $sensor['name'], 'archived' => true];
    }

    public function poll(int $id): array
    {
        $sensor = $this->find($id);
        if ($sensor === null) {
            throw new RuntimeException('Sensor not found');
        }
        return $this->pollBatch([$sensor])[0];
    }

    public function pollAll(): array
    {
        return $this->pollBatch($this->all());
    }

    public function pingAll(): array
    {
        $sensors = array_values(array_filter(
            $this->all(),
            static fn (array $sensor): bool => $sensor['ping_enabled'] && $sensor['monitoring_enabled'],
        ));
        return array_map(function (array $sensor): array {
            $result = $this->ping($sensor['host']);
            return ['id' => $sensor['id'], 'name' => $sensor['name'], 'ping' => $result];
        }, $sensors);
    }

    /**
     * Polls every given sensor concurrently instead of one at a time: a
     * single batched fping call covers every host that wants a reachability
     * check, and every configured SNMP OID goes out over its own
     * non-blocking socket under one shared timeout budget. fping is also
     * started before the SNMP round and only collected afterwards, so the
     * two phases run alongside each other rather than one after the other.
     * With dozens of sensors this is the difference between "roughly the
     * slowest single check" and "the sum of every check, one after another."
     */
    private function pollBatch(array $sensors): array
    {
        $activeSensors = array_values(array_filter($sensors, static fn (array $sensor): bool => $sensor['monitoring_enabled']));

        $pingHosts = array_values(array_unique(array_column(
            array_filter($activeSensors, static fn (array $sensor): bool => $sensor['ping_enabled']),
            'host',
        )));
        $pingBatch = $pingHosts !== [] ? $this->fping->startBatch($pingHosts) : null;

        $snmpRequests = [];
        foreach ($activeSensors as $sensor) {
            foreach (['temperature_oid', 'humidity_oid'] as $oidField) {
                $oid = $sensor[$oidField];
                if ($oid !== null && $oid !== '') {
                    $snmpRequests[$sensor['id'] . ':' . $oidField] = [
                        'host' => (string) $sensor['host'],
                        'port' => (int) $sensor['snmp_port'],
                        'community' => (string) $sensor['snmp_community'],
                        'oid' => $oid,
                    ];
                }
            }
            foreach ($sensor['inputs'] as $input) {
                if (($input['alarm_state_oid'] ?? '') !== '') {
                    $snmpRequests['input:' . $input['id']] = [
                        'host' => (string) $sensor['host'],
                        'port' => (int) $sensor['snmp_port'],
                        'community' => (string) $sensor['snmp_community'],
                        'oid' => $input['alarm_state_oid'],
                    ];
                }
            }
            foreach ($sensor['channels'] as $channel) {
                if (($channel['value_oid'] ?? '') !== '') {
                    $snmpRequests['channel:' . $channel['id']] = [
                        'host' => (string) $sensor['host'],
                        'port' => (int) $sensor['snmp_port'],
                        'community' => (string) $sensor['snmp_community'],
                        'oid' => $channel['value_oid'],
                    ];
                }
            }
        }
        $snmpResults = $snmpRequests !== [] ? $this->snmp->getMany($snmpRequests) : [];
        $pingResults = $this->fping->collectBatch($pingBatch);

        return array_map(function (array $sensor) use ($pingResults, $snmpResults): array {
            if (!$sensor['monitoring_enabled']) {
                $sensor['ping'] = null;
                $sensor['temperature'] = ['configured' => false, 'ok' => false, 'raw' => null, 'value' => null, 'error' => null];
                $sensor['humidity'] = ['configured' => false, 'ok' => false, 'raw' => null, 'value' => null, 'error' => null];
                $sensor['alarm'] = ['active' => false, 'reasons' => []];
                return $sensor;
            }

            $sensor['ping'] = $sensor['ping_enabled'] ? ($pingResults[$sensor['host']] ?? ['ok' => false, 'latency_ms' => null]) : null;
            $sensor['temperature'] = $this->interpretSnmpResult($sensor, 'temperature_oid', 'temperature_divisor', $snmpResults[$sensor['id'] . ':temperature_oid'] ?? null);
            $sensor['humidity'] = $this->interpretSnmpResult($sensor, 'humidity_oid', 'humidity_divisor', $snmpResults[$sensor['id'] . ':humidity_oid'] ?? null);

            foreach ($sensor['inputs'] as &$input) {
                $result = $snmpResults['input:' . $input['id']] ?? null;
                if ($result !== null && $result['ok']) {
                    $input['last_alarm_state'] = (int) round((float) $result['value']);
                    $this->cacheInputReading($input['id'], $input['last_alarm_state']);
                }
            }
            unset($input);

            foreach ($sensor['channels'] as &$channel) {
                $result = $snmpResults['channel:' . $channel['id']] ?? null;
                $value = $result !== null && $result['ok']
                    ? $this->scaleValue((string) $result['value'], (string) $result['type'], $channel['value_divisor'])
                    : null;
                if ($value !== null) {
                    $channel['last_value'] = $value;
                    $this->cacheChannelReading($channel['id'], $value);
                }
            }
            unset($channel);

            $reasons = [];
            if ($sensor['ping'] !== null && !$sensor['ping']['ok']) {
                $reasons[] = 'ping';
            }
            if ($sensor['temperature']['ok'] && $this->outOfRange($sensor['temperature']['value'], $sensor['temperature_min'], $sensor['temperature_max'])) {
                $reasons[] = 'temperature';
            }
            if ($sensor['humidity']['ok'] && $this->outOfRange($sensor['humidity']['value'], $sensor['humidity_min'], $sensor['humidity_max'])) {
                $reasons[] = 'humidity';
            }
            // The "agregat" (generator) group gets its own amber badge on
            // the tile but doesn't escalate to the general red alarm ring —
            // mirroring how the list view prioritizes grid-power loss
            // ("miasto") as the more critical condition of the two.
            if (array_filter($sensor['inputs'], static fn (array $i): bool => $i['last_alarm_state'] === 2 && $i['group'] !== 'agregat') !== []) {
                $reasons[] = 'inputs';
            }
            $sensor['alarm'] = $reasons !== [] ? ['active' => true, 'reasons' => $reasons] : ['active' => false, 'reasons' => []];

            $this->cacheReading($sensor);

            return $sensor;
        }, $sensors);
    }

    private function cacheInputReading(int $inputId, int $alarmState): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE environmental_sensor_inputs SET last_alarm_state = :state, last_read_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $statement->execute(['id' => $inputId, 'state' => $alarmState]);
    }

    private function cacheChannelReading(int $channelId, float $value): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE environmental_sensor_channels SET last_reading = :value, last_read_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $statement->execute(['id' => $channelId, 'value' => $value]);
    }

    /**
     * Write-through cache so the next page load can render last-known
     * readings immediately instead of waiting on a fresh live SNMP/ping
     * round trip. Temperature/humidity only overwrite the cache on a
     * successful read (a transient failure keeps showing the last good
     * value); ping status always overwrites, since "down" is itself
     * meaningful information worth surfacing immediately.
     */
    private function cacheReading(array $sensor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE environmental_sensors SET
                last_temperature = COALESCE(:last_temperature, last_temperature),
                last_humidity = COALESCE(:last_humidity, last_humidity),
                last_ping_ok = COALESCE(:last_ping_ok, last_ping_ok),
                last_ping_latency_ms = COALESCE(:last_ping_latency_ms, last_ping_latency_ms),
                last_read_at = CURRENT_TIMESTAMP
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $sensor['id'],
            'last_temperature' => $sensor['temperature']['ok'] ? $sensor['temperature']['value'] : null,
            'last_humidity' => $sensor['humidity']['ok'] ? $sensor['humidity']['value'] : null,
            'last_ping_ok' => $sensor['ping'] !== null ? ($sensor['ping']['ok'] ? 1 : 0) : null,
            'last_ping_latency_ms' => ($sensor['ping']['ok'] ?? false) ? $sensor['ping']['latency_ms'] : null,
        ]);
    }

    private function outOfRange(float $value, ?float $min, ?float $max): bool
    {
        if ($min !== null && $value < $min) {
            return true;
        }
        return $max !== null && $value > $max;
    }

    /**
     * @param array{ok: bool, value: string|null, type: string|null, error: string|null}|null $result
     *     pre-fetched by a prior batched SnmpClient::getMany() call — this only interprets it.
     */
    private function interpretSnmpResult(array $sensor, string $oidField, string $divisorField, ?array $result): array
    {
        $oid = $sensor[$oidField];
        if ($oid === null || $oid === '') {
            return ['configured' => false, 'ok' => false, 'raw' => null, 'value' => null, 'error' => null];
        }
        if ($result === null || !$result['ok']) {
            return ['configured' => true, 'ok' => false, 'raw' => null, 'value' => null, 'error' => $result['error'] ?? 'No response (timeout)'];
        }
        $value = $this->scaleValue((string) $result['value'], (string) $result['type'], (float) $sensor[$divisorField]);
        return ['configured' => true, 'ok' => $value !== null, 'raw' => $result['value'], 'value' => $value, 'error' => $value === null ? 'Could not parse numeric value' : null];
    }

    private function scaleValue(string $raw, string $type, float $divisor): ?float
    {
        if (in_array($type, ['integer', 'counter'], true)) {
            return $divisor > 0 ? ((float) $raw) / $divisor : (float) $raw;
        }
        if (preg_match('/-?\d+(?:\.\d+)?/', $raw, $matches) === 1) {
            return (float) $matches[0];
        }
        return null;
    }

    private function ping(string $host): array
    {
        if (!$this->isValidHost($host)) {
            return ['ok' => false, 'latency_ms' => null];
        }
        $command = PHP_OS_FAMILY === 'Windows'
            ? sprintf('ping -n 1 -w 1000 %s', escapeshellarg($host))
            : sprintf('ping -c 1 -W 1 %s', escapeshellarg($host));
        exec($command . ' 2>&1', $output, $exitCode);
        $latency = null;
        foreach ($output as $line) {
            if (preg_match('/time[=<]([\d.]+)\s*ms/i', $line, $matches) === 1) {
                $latency = (float) $matches[1];
                break;
            }
        }
        return ['ok' => $exitCode === 0, 'latency_ms' => $latency];
    }

    private function isValidHost(string $host): bool
    {
        return $host !== '' && preg_match('/^[a-zA-Z0-9.\-:]+$/', $host) === 1;
    }

    private function sensorRecord(array $input): array
    {
        $name = trim((string) $input['name']);
        $host = trim((string) $input['host']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new RuntimeException('Name must contain 2-160 characters');
        }
        if ($host === '' || mb_strlen($host) > 255) {
            throw new RuntimeException('Host is required');
        }
        $port = (int) ($input['snmp_port'] ?? 161);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('SNMP port must be between 1 and 65535');
        }
        return [
            'name' => $name,
            'model' => trim((string) ($input['model'] ?? '')) ?: null,
            'icon' => trim((string) ($input['icon'] ?? '')) ?: null,
            'host' => $host,
            'snmp_port' => $port,
            'snmp_community' => trim((string) ($input['snmp_community'] ?? '')) ?: 'public',
            'temperature_oid' => trim((string) ($input['temperature_oid'] ?? '')) ?: null,
            'temperature_divisor' => (float) ($input['temperature_divisor'] ?? 10),
            'temperature_min' => $this->nullableFloat($input['temperature_min'] ?? null),
            'temperature_max' => $this->nullableFloat($input['temperature_max'] ?? null),
            'humidity_oid' => trim((string) ($input['humidity_oid'] ?? '')) ?: null,
            'humidity_divisor' => (float) ($input['humidity_divisor'] ?? 10),
            'humidity_min' => $this->nullableFloat($input['humidity_min'] ?? null),
            'humidity_max' => $this->nullableFloat($input['humidity_max'] ?? null),
            'ping_enabled' => !empty($input['ping_enabled']) ? 1 : 0,
            'monitoring_enabled' => !empty($input['monitoring_enabled']) ? 1 : 0,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'model' => $row['model'],
            'icon' => $row['icon'],
            'host' => $row['host'],
            'snmp_port' => (int) $row['snmp_port'],
            'snmp_community' => $row['snmp_community'],
            'temperature_oid' => $row['temperature_oid'],
            'temperature_divisor' => (float) $row['temperature_divisor'],
            'temperature_min' => $row['temperature_min'] !== null ? (float) $row['temperature_min'] : null,
            'temperature_max' => $row['temperature_max'] !== null ? (float) $row['temperature_max'] : null,
            'humidity_oid' => $row['humidity_oid'],
            'humidity_divisor' => (float) $row['humidity_divisor'],
            'humidity_min' => $row['humidity_min'] !== null ? (float) $row['humidity_min'] : null,
            'humidity_max' => $row['humidity_max'] !== null ? (float) $row['humidity_max'] : null,
            'ping_enabled' => (bool) $row['ping_enabled'],
            'monitoring_enabled' => (bool) $row['monitoring_enabled'],
            'last_temperature' => $row['last_temperature'] !== null ? (float) $row['last_temperature'] : null,
            'last_humidity' => $row['last_humidity'] !== null ? (float) $row['last_humidity'] : null,
            'last_ping_ok' => $row['last_ping_ok'] !== null ? (bool) $row['last_ping_ok'] : null,
            'last_ping_latency_ms' => $row['last_ping_latency_ms'] !== null ? (float) $row['last_ping_latency_ms'] : null,
            'last_read_at' => $row['last_read_at'],
            'notes' => $row['notes'],
        ];
    }

    public function getTileLayout(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT layout FROM user_sensor_tile_layout WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        $raw = $statement->fetchColumn();
        if ($raw === false) {
            return ['order' => [], 'sizes' => []];
        }
        $decoded = json_decode((string) $raw, true) ?: [];
        return [
            'order' => array_map('intval', $decoded['order'] ?? []),
            'sizes' => array_map('strval', $decoded['sizes'] ?? []),
        ];
    }

    public function saveTileLayout(int $userId, array $order, array $sizes): void
    {
        $allowedSizes = ['small', 'medium', 'large'];
        $cleanOrder = array_values(array_unique(array_map('intval', $order)));
        $cleanSizes = [];
        foreach ($sizes as $sensorId => $size) {
            $sensorId = (int) $sensorId;
            if ($sensorId > 0 && in_array($size, $allowedSizes, true)) {
                $cleanSizes[(string) $sensorId] = $size;
            }
        }
        $layout = json_encode(['order' => $cleanOrder, 'sizes' => $cleanSizes], JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO user_sensor_tile_layout (user_id, layout) VALUES (:user_id, :layout)
             ON DUPLICATE KEY UPDATE layout = VALUES(layout)',
        );
        $statement->execute(['user_id' => $userId, 'layout' => $layout]);
    }

    private function recordAudit(string $entityType, int $entityId, string $action, array $after): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events (user_id, entity_type, entity_id, action, before_data, after_data, ip_address)
             VALUES (:user_id, :entity_type, :entity_id, :action, NULL, :after_data, :ip_address)',
        );
        $statement->execute([
            'user_id' => $_SESSION['user_id'] ?? null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'after_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
