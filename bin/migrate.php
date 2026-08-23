<?php

declare(strict_types=1);

use NStructure\Infrastructure\Database\ConnectionFactory;
use Symfony\Component\Dotenv\Dotenv;

$rootPath = dirname(__DIR__);
require $rootPath . '/vendor/autoload.php';

if (is_file($rootPath . '/.env')) {
    (new Dotenv())->usePutenv()->loadEnv($rootPath . '/.env');
}

$migrationName = $argv[1] ?? '';
if (!preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/', $migrationName)) {
    fwrite(STDERR, "Provide one migration filename, for example 004_port_remote_endpoint.sql.\n");
    exit(2);
}

$migrationPath = $rootPath . '/database/migrations/' . $migrationName;
if (!is_file($migrationPath)) {
    fwrite(STDERR, "Migration file was not found.\n");
    exit(2);
}

$settingsFactory = require $rootPath . '/config/settings.php';
$settings = $settingsFactory($rootPath);
$pdo = ConnectionFactory::create($settings['database']);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
);

$check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration');
$check->execute(['migration' => $migrationName]);
if ((int) $check->fetchColumn() > 0) {
    fwrite(STDOUT, "Migration already applied: {$migrationName}\n");
    exit(0);
}

$sql = file_get_contents($migrationPath);
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "Migration file is empty.\n");
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
