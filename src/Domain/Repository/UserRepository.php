<?php

declare(strict_types=1);

namespace NStructure\Domain\Repository;

interface UserRepository
{
    public function all(): array;

    public function findById(int $id): ?array;

    public function findActiveByEmail(string $email): ?array;

    public function create(array $input): array;

    public function updateProfile(int $userId, string $name, string $email): array;

    public function archive(int $userId): array;

    public function touchLastLogin(int $userId): void;

    public function verifyPassword(int $userId, string $password): bool;

    public function updatePassword(int $userId, string $newPassword): void;

    public function countActive(): int;

    public function auditLog(int $limit = 100): array;

    /**
     * The account that owns this installation (its first-ever user) —
     * protected from removal regardless of who's doing the removing, not
     * just from removing themselves.
     */
    public function ownerId(): int;
}
