<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Repository\AlertRepository;
use RuntimeException;

/**
 * Same rationale as DemoSensorRepository: the sensors page itself isn't
 * route-gated behind demo_mode (only its nav link and mutating API routes
 * are), so visiting /tools/sensors in a demo deployment would otherwise
 * still try to open a real database connection through this repository.
 */
final class DemoAlertRepository implements AlertRepository
{
    public function listRecipients(): array
    {
        return [];
    }

    public function createRecipient(array $input): array
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }

    public function updateRecipient(int $id, array $input): array
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }

    public function archiveRecipient(int $id): array
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }

    public function listGroups(): array
    {
        return [];
    }

    public function createGroup(array $input): array
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }

    public function updateGroup(int $id, array $input): array
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }

    public function deleteGroup(int $id): void
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }

    public function setGroupMembers(int $groupId, array $recipientIds): void
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }

    public function getSensorAlertTargets(int $sensorId): array
    {
        return ['recipient_ids' => [], 'group_ids' => []];
    }

    public function setSensorAlertTargets(int $sensorId, array $recipientIds, array $groupIds): void
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }

    public function listAllSensorAlertTargets(): array
    {
        return [];
    }

    public function getSettings(): array
    {
        return ['repeat_interval_minutes' => 60];
    }

    public function saveSettings(int $repeatIntervalMinutes): void
    {
        throw new RuntimeException('Alerts are not available in demo mode');
    }
}
