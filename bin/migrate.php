<?php

declare(strict_types=1);

use NStructure\Infrastructure\Database\ConnectionFactory;
use Symfony\Component\Dotenv\Dotenv;

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';

if (is_file($rootPath . '/.env')) {
    (new Dotenv())->usePutenv()->loadEnv($rootPath . '/.env');
}

$requested = $argv[1] ?? '';
if ($requested !== '' && !preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/', $requested)) {
    fwrite(STDERR, "Provide one migration filename (e.g. 004_port_remote_endpoint.sql) or no argument to run every pending migration.\n");
    exit(2);
}

$migrationsPath = $rootPath . '/database/migrations';
if ($requested !== '') {
    $migrationFiles = [$requested];
} else {
    $migrationFiles = array_values(array_filter(array_map(
        static fn (string $path): string => basename($path),
        glob($migrationsPath . '/*.sql') ?: [],
    )));
    sort($migrationFiles);
}

if ($migrationFiles === []) {
    fwrite(STDERR, "No migration files found in database/migrations.\n");
    exit(2);
}

$settingsFactory = require $rootPath . '/config/settings.php';
$settings = $settingsFactory($rootPath);
$pdo = ConnectionFactory::create($settings['database']);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
);

foreach ($migrationFiles as $migrationName) {
    $migrationPath = $migrationsPath . '/' . $migrationName;
    if (!is_file($migrationPath)) {
        fwrite(STDERR, "Migration file was not found: {$migrationName}\n");
        exit(2);
    }

    $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration');
    $check->execute(['migration' => $migrationName]);
    if ((int) $check->fetchColumn() > 0) {
        fwrite(STDOUT, "Migration already applied: {$migrationName}\n");
        continue;
    }

    $sql = file_get_contents($migrationPath);
    if (!is_string($sql) || trim($sql) === '') {
        fwrite(STDERR, "Migration file is empty: {$migrationName}\n");
        exit(2);
    }

    $checksum = hash('sha256', $sql);
    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1060) {
            throw $exception;
        }
    }
    $record = $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (:migration, :checksum)');
    $record->execute(['migration' => $migrationName, 'checksum' => $checksum]);
    fwrite(STDOUT, "Migration applied: {$migrationName}\n");
}
