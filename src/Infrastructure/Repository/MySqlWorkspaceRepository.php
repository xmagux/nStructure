<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Repository\WorkspaceRepository;
use PDO;

final class MySqlWorkspaceRepository implements WorkspaceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(): array
    {
        $row = $this->pdo->query('SELECT tier, region, network_map_subtitle FROM workspace_settings WHERE id = 1')->fetch();
        return [
            'tier' => $row['tier'] ?: null,
            'region' => $row['region'] ?: null,
            'network_map_subtitle' => $row['network_map_subtitle'] ?: null,
        ];
    }

    public function update(array $input): array
    {
        $record = [
            'tier' => trim((string) ($input['tier'] ?? '')) ?: null,
            'region' => trim((string) ($input['region'] ?? '')) ?: null,
            'network_map_subtitle' => trim((string) ($input['network_map_subtitle'] ?? '')) ?: null,
        ];
        $statement = $this->pdo->prepare(
            'INSERT INTO workspace_settings (id, tier, region, network_map_subtitle) VALUES (1, :tier, :region, :network_map_subtitle)
             ON DUPLICATE KEY UPDATE tier = VALUES(tier), region = VALUES(region), network_map_subtitle = VALUES(network_map_subtitle)',
        );
        $statement->execute($record);
        return $record;
    }
}
