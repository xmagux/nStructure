<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Repository\SensorRepository;
use NStructure\Infrastructure\Snmp\SnmpClient;
use PDO;
use RuntimeException;

final class MySqlSensorRepository implements SensorRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SnmpClient $snmp,
    ) {
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT * FROM environmental_sensors WHERE archived_at IS NULL ORDER BY name',
        );
        return array_map($this->normalize(...), $statement->fetchAll());
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM environmental_sensors WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->normalize($row) : null;
    }

    public function create(array $input): array
    {
        $record = $this->sensorRecord($input);
        $statement = $this->pdo->prepare(
            'INSERT INTO environmental_sensors (
                name, model, host, snmp_port, snmp_community,
                temperature_oid, temperature_divisor, humidity_oid, humidity_divisor,
                ping_enabled, notes
             ) VALUES (
                :name, :model, :host, :snmp_port, :snmp_community,
                :temperature_oid, :temperature_divisor, :humidity_oid, :humidity_divisor,
                :ping_enabled, :notes
             )',
        );
        $statement->execute($record);
        $id = (int) $this->pdo->lastInsertId();
        $sensor = $this->find($id) ?? throw new RuntimeException('Sensor could not be loaded');
        $this->recordAudit('ENVIRONMENTAL_SENSOR', $id, 'CREATE', $sensor);
        return $sensor;
    }

    public function update(int $id, array $input): array
    {
        $record = $this->sensorRecord($input) + ['id' => $id];
        $statement = $this->pdo->prepare(
            'UPDATE environmental_sensors SET
                name = :name, model = :model, host = :host, snmp_port = :snmp_port, snmp_community = :snmp_community,
                temperature_oid = :temperature_oid, temperature_divisor = :temperature_divisor,
                humidity_oid = :humidity_oid, humidity_divisor = :humidity_divisor,
                ping_enabled = :ping_enabled, notes = :notes
             WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute($record);
        $sensor = $this->find($id);
        if ($sensor === null) {
            throw new RuntimeException('Sensor not found');
        }
        $this->recordAudit('ENVIRONMENTAL_SENSOR', $id, 'UPDATE', $sensor);
        return $sensor;
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
        return $this->pollSensor($sensor);
    }

    public function pollAll(): array
    {
        return array_map(fn (array $sensor): array => $this->pollSensor($sensor), $this->all());
    }

    private function pollSensor(array $sensor): array
    {
        $sensor['ping'] = $sensor['ping_enabled'] ? $this->ping($sensor['host']) : null;
        $sensor['temperature'] = $this->readValue($sensor, 'temperature_oid', 'temperature_divisor');
        $sensor['humidity'] = $this->readValue($sensor, 'humidity_oid', 'humidity_divisor');
        $this->recordReading($sensor);
        return $sensor;
    }

    private function recordReading(array $sensor): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO environmental_sensor_readings (
                sensor_id, temperature, temperature_raw, temperature_ok,
                humidity, humidity_raw, humidity_ok, ping_ok, ping_latency_ms
             ) VALUES (
                :sensor_id, :temperature, :temperature_raw, :temperature_ok,
                :humidity, :humidity_raw, :humidity_ok, :ping_ok, :ping_latency_ms
             )',
        );
        $statement->execute([
            'sensor_id' => $sensor['id'],
            'temperature' => $sensor['temperature']['ok'] ? $sensor['temperature']['value'] : null,
            'temperature_raw' => $sensor['temperature']['raw'] !== null ? mb_substr((string) $sensor['temperature']['raw'], 0, 64) : null,
            'temperature_ok' => $sensor['temperature']['ok'] ? 1 : 0,
            'humidity' => $sensor['humidity']['ok'] ? $sensor['humidity']['value'] : null,
            'humidity_raw' => $sensor['humidity']['raw'] !== null ? mb_substr((string) $sensor['humidity']['raw'], 0, 64) : null,
            'humidity_ok' => $sensor['humidity']['ok'] ? 1 : 0,
            'ping_ok' => $sensor['ping'] === null ? null : ($sensor['ping']['ok'] ? 1 : 0),
            'ping_latency_ms' => $sensor['ping']['latency_ms'] ?? null,
        ]);
    }

    private function readValue(array $sensor, string $oidField, string $divisorField): array
    {
        $oid = $sensor[$oidField];
        if ($oid === null || $oid === '') {
            return ['configured' => false, 'ok' => false, 'raw' => null, 'value' => null, 'error' => null];
        }
        $result = $this->snmp->get((string) $sensor['host'], (int) $sensor['snmp_port'], (string) $sensor['snmp_community'], $oid);
        if (!$result['ok']) {
            return ['configured' => true, 'ok' => false, 'raw' => null, 'value' => null, 'error' => $result['error']];
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
            'host' => $host,
            'snmp_port' => $port,
            'snmp_community' => trim((string) ($input['snmp_community'] ?? '')) ?: 'public',
            'temperature_oid' => trim((string) ($input['temperature_oid'] ?? '')) ?: null,
            'temperature_divisor' => (float) ($input['temperature_divisor'] ?? 10),
            'humidity_oid' => trim((string) ($input['humidity_oid'] ?? '')) ?: null,
            'humidity_divisor' => (float) ($input['humidity_divisor'] ?? 10),
            'ping_enabled' => !empty($input['ping_enabled']) ? 1 : 0,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
    }

    private function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'model' => $row['model'],
            'host' => $row['host'],
            'snmp_port' => (int) $row['snmp_port'],
            'snmp_community' => $row['snmp_community'],
            'temperature_oid' => $row['temperature_oid'],
            'temperature_divisor' => (float) $row['temperature_divisor'],
            'humidity_oid' => $row['humidity_oid'],
            'humidity_divisor' => (float) $row['humidity_divisor'],
            'ping_enabled' => (bool) $row['ping_enabled'],
            'notes' => $row['notes'],
        ];
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
