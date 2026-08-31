<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Repository\WorkspaceRepository;
use RuntimeException;

final class DemoWorkspaceRepository implements WorkspaceRepository
{
    public function get(): array
    {
        return ['tier' => null, 'region' => null, 'network_map_subtitle' => null];
    }

    public function update(array $input): array
    {
        throw new RuntimeException('Workspace settings are not available in demo mode');
    }
}
