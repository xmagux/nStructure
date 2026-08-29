<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Repository\AlertRepository;
use PDO;
use RuntimeException;

final class MySqlAlertRepository implements AlertRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listRecipients(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, email, name, archived_at FROM alert_recipients WHERE archived_at IS NULL ORDER BY email',
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'name' => $row['name'],
        ], $statement->fetchAll());
    }

    public function createRecipient(array $input): array
    {
        $record = $this->recipientRecord($input);
        $statement = $this->pdo->prepare(
            'INSERT INTO alert_recipients (email, name) VALUES (:email, :name)',
        );
        $statement->execute($record);
        $id = (int) $this->pdo->lastInsertId();
        return ['id' => $id, 'email' => $record['email'], 'name' => $record['name']];
    }

    public function updateRecipient(int $id, array $input): array
    {
        $record = $this->recipientRecord($input);
        $statement = $this->pdo->prepare(
            'UPDATE alert_recipients SET email = :email, name = :name WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute($record + ['id' => $id]);
        return ['id' => $id, 'email' => $record['email'], 'name' => $record['name']];
    }

    public function archiveRecipient(int $id): array
    {
        $statement = $this->pdo->prepare(
            'UPDATE alert_recipients SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute(['id' => $id]);
        return ['id' => $id, 'archived' => true];
    }

    private function recipientRecord(array $input): array
    {
        $email = trim((string) ($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) > 160) {
            throw new RuntimeException('Name must be at most 160 characters');
        }
        return ['email' => $email, 'name' => $name ?: null];
    }

    public function listGroups(): array
    {
        $groups = $this->pdo->query('SELECT id, name FROM alert_groups ORDER BY name')->fetchAll();
        if ($groups === []) {
            return [];
        }
        $ids = array_column($groups, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT agm.group_id, r.id, r.email, r.name
             FROM alert_group_members agm
             JOIN alert_recipients r ON r.id = agm.recipient_id
             WHERE agm.group_id IN ($placeholders) AND r.archived_at IS NULL
             ORDER BY r.email",
        );
        $statement->execute($ids);
        $membersByGroup = [];
        foreach ($statement->fetchAll() as $row) {
            $membersByGroup[(int) $row['group_id']][] = [
                'id' => (int) $row['id'],
                'email' => $row['email'],
                'name' => $row['name'],
            ];
        }

        return array_map(static function (array $group) use ($membersByGroup): array {
            $members = $membersByGroup[(int) $group['id']] ?? [];
            return [
                'id' => (int) $group['id'],
                'name' => $group['name'],
                'members' => $members,
                'member_ids' => array_column($members, 'id'),
            ];
        }, $groups);
    }

    public function createGroup(array $input): array
    {
        $name = $this->groupName($input);
        $statement = $this->pdo->prepare('INSERT INTO alert_groups (name) VALUES (:name)');
        $statement->execute(['name' => $name]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'name' => $name, 'members' => []];
    }

    public function updateGroup(int $id, array $input): array
    {
        $name = $this->groupName($input);
        $statement = $this->pdo->prepare('UPDATE alert_groups SET name = :name WHERE id = :id');
        $statement->execute(['id' => $id, 'name' => $name]);
        return ['id' => $id, 'name' => $name];
    }

    private function groupName(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 1 || mb_strlen($name) > 160) {
            throw new RuntimeException('Group name must contain 1-160 characters');
        }
        return $name;
    }

    public function deleteGroup(int $id): void
    {
        $clearTargets = $this->pdo->prepare(
            "DELETE FROM sensor_alert_targets WHERE target_type = 'group' AND target_id = :id",
        );
        $clearTargets->execute(['id' => $id]);
        $statement = $this->pdo->prepare('DELETE FROM alert_groups WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function setGroupMembers(int $groupId, array $recipientIds): void
    {
        $delete = $this->pdo->prepare('DELETE FROM alert_group_members WHERE group_id = :group_id');
        $delete->execute(['group_id' => $groupId]);
        $ids = array_values(array_unique(array_filter(array_map('intval', $recipientIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO alert_group_members (group_id, recipient_id) VALUES (:group_id, :recipient_id)',
        );
        foreach ($ids as $recipientId) {
            $insert->execute(['group_id' => $groupId, 'recipient_id' => $recipientId]);
        }
    }

    public function getSensorAlertTargets(int $sensorId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT target_type, target_id FROM sensor_alert_targets WHERE sensor_id = :sensor_id',
        );
        $statement->execute(['sensor_id' => $sensorId]);
        $recipientIds = [];
        $groupIds = [];
        foreach ($statement->fetchAll() as $row) {
            if ($row['target_type'] === 'recipient') {
                $recipientIds[] = (int) $row['target_id'];
            } else {
                $groupIds[] = (int) $row['target_id'];
            }
        }
        return ['recipient_ids' => $recipientIds, 'group_ids' => $groupIds];
    }

    public function listAllSensorAlertTargets(): array
    {
        $statement = $this->pdo->query('SELECT sensor_id, target_type, target_id FROM sensor_alert_targets');
        $bySensor = [];
        foreach ($statement->fetchAll() as $row) {
            $sensorId = (int) $row['sensor_id'];
            $bySensor[$sensorId] ??= ['recipient_ids' => [], 'group_ids' => []];
            if ($row['target_type'] === 'recipient') {
                $bySensor[$sensorId]['recipient_ids'][] = (int) $row['target_id'];
            } else {
                $bySensor[$sensorId]['group_ids'][] = (int) $row['target_id'];
            }
        }
        return $bySensor;
    }

    public function setSensorAlertTargets(int $sensorId, array $recipientIds, array $groupIds): void
    {
        $delete = $this->pdo->prepare('DELETE FROM sensor_alert_targets WHERE sensor_id = :sensor_id');
        $delete->execute(['sensor_id' => $sensorId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO sensor_alert_targets (sensor_id, target_type, target_id) VALUES (:sensor_id, :target_type, :target_id)',
        );
        foreach (array_unique(array_map('intval', $recipientIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $insert->execute(['sensor_id' => $sensorId, 'target_type' => 'recipient', 'target_id' => $id]);
        }
        foreach (array_unique(array_map('intval', $groupIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $insert->execute(['sensor_id' => $sensorId, 'target_type' => 'group', 'target_id' => $id]);
        }
    }

    public function getSettings(): array
    {
        $value = $this->pdo->query('SELECT repeat_interval_minutes FROM alert_settings WHERE id = 1')->fetchColumn();
        return ['repeat_interval_minutes' => $value !== false ? (int) $value : 60];
    }

    public function saveSettings(int $repeatIntervalMinutes): void
    {
        $minutes = max(1, $repeatIntervalMinutes);
        $statement = $this->pdo->prepare(
            'INSERT INTO alert_settings (id, repeat_interval_minutes) VALUES (1, :minutes)
             ON DUPLICATE KEY UPDATE repeat_interval_minutes = VALUES(repeat_interval_minutes)',
        );
        $statement->execute(['minutes' => $minutes]);
    }
}
