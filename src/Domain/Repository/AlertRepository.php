<?php

declare(strict_types=1);

namespace NStructure\Domain\Repository;

interface AlertRepository
{
    public function listRecipients(): array;

    public function createRecipient(array $input): array;

    public function updateRecipient(int $id, array $input): array;

    public function archiveRecipient(int $id): array;

    public function listGroups(): array;

    public function createGroup(array $input): array;

    public function updateGroup(int $id, array $input): array;

    public function deleteGroup(int $id): void;

    public function setGroupMembers(int $groupId, array $recipientIds): void;

    /**
     * @return array{recipient_ids: int[], group_ids: int[]}
     */
    public function getSensorAlertTargets(int $sensorId): array;

    public function setSensorAlertTargets(int $sensorId, array $recipientIds, array $groupIds): void;

    /**
     * @return array<int, array{recipient_ids: int[], group_ids: int[]}> keyed by sensor_id
     */
    public function listAllSensorAlertTargets(): array;

    /**
     * @return array{repeat_interval_minutes: int}
     */
    public function getSettings(): array;

    public function saveSettings(int $repeatIntervalMinutes): void;
}
