<?php

declare(strict_types=1);

namespace NStructure\Domain\Repository;

interface SensorRepository
{
    public function all(): array;

    public function find(int $id): ?array;

    public function create(array $input): array;

    public function update(int $id, array $input): array;

    public function archive(int $id): array;

    public function poll(int $id): array;

    public function pollAll(): array;

    public function pingAll(): array;
}
