<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Database;

use PDO;
use RuntimeException;

final readonly class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $migrationPath,
    ) {
    }

    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'migration VARCHAR(190) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        );

        $files = glob($this->migrationPath . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException(sprintf('Unable to hash migration: %s', $name));
            }

            $statement = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = :migration');
            $statement->execute(['migration' => $name]);
            $existingChecksum = $statement->fetchColumn();

            if (is_string($existingChecksum)) {
                if (!hash_equals($existingChecksum, $checksum)) {
                    throw new RuntimeException(sprintf('Applied migration was modified: %s', $name));
                }
                continue;
            }

            SqlFileRunner::run($this->pdo, $file);
            $insert = $this->pdo->prepare(
                'INSERT INTO schema_migrations (migration, checksum) VALUES (:migration, :checksum)',
            );
            $insert->execute(['migration' => $name, 'checksum' => $checksum]);
            $applied[] = $name;
        }

        return $applied;
    }
}
