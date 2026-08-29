<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Repository\SensorRepository;
use RuntimeException;

/**
 * The environmental sensors module is excluded entirely in demo mode (its
 * nav link and API routes are both gated on demo_mode in routes.php), so
 * this only exists to give the DI container something to hand out that
 * doesn't open a real database/SNMP connection — needed because
 * PageController reads the sensor list for the server-room picker on every
 * request, demo mode included.
 */
final class DemoSensorRepository implements SensorRepository
{
    public function all(): array
    {
        return [];
    }

    public function find(int $id): ?array
    {
        return null;
    }

    public function create(array $input): array
    {
        throw new RuntimeException('Sensors are not available in demo mode');
    }

    public function update(int $id, array $input): array
    {
        throw new RuntimeException('Sensors are not available in demo mode');
    }

    public function archive(int $id): array
    {
        throw new RuntimeException('Sensors are not available in demo mode');
    }

    public function poll(int $id): array
    {
        throw new RuntimeException('Sensors are not available in demo mode');
    }

    public function pollAll(): array
    {
        return [];
    }

    public function pingAll(): array
    {
        return [];
    }

    public function getTileLayout(int $userId): array
    {
        return ['order' => [], 'sizes' => []];
    }

    public function saveTileLayout(int $userId, array $order, array $sizes): void
    {
    }
}
