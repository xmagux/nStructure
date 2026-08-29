<?php

declare(strict_types=1);

return static function (string $rootPath): array {
    $env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? getenv($key) ?: $default;

    return [
        'root_path' => $rootPath,
        'app' => [
            'name' => 'nStructure',
            'environment' => (string) $env('APP_ENV', 'production'),
            'debug' => filter_var($env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
            'url' => rtrim((string) $env('APP_URL', 'http://127.0.0.1:8080'), '/'),
            'locale' => (string) $env('APP_LOCALE', 'en'),
            'demo_mode' => filter_var($env('APP_DEMO_MODE', true), FILTER_VALIDATE_BOOL),
            'session_name' => (string) $env('APP_SESSION_NAME', 'nstructure_session'),
        ],
        'database' => [
            'host' => (string) $env('DB_HOST', '127.0.0.1'),
            'port' => (int) $env('DB_PORT', 3306),
            'database' => (string) $env('DB_DATABASE', 'nstructure'),
            'username' => (string) $env('DB_USERNAME', 'nstructure'),
            'password' => (string) $env('DB_PASSWORD', ''),
        ],
        'view' => [
            'path' => $rootPath . '/resources/views',
            'cache' => false,
        ],
        'metrics' => [
            'victoriametrics_url' => rtrim((string) $env('VICTORIAMETRICS_URL', 'http://127.0.0.1:8428'), '/'),
            'heartbeat_file' => (string) $env(
                'SENSOR_HEARTBEAT_FILE',
                is_dir('/dev/shm') ? '/dev/shm/nstructure-sensor-heartbeats.json' : sys_get_temp_dir() . '/nstructure-sensor-heartbeats.json',
            ),
        ],
        'mail' => [
            'host' => (string) $env('SMTP_HOST', ''),
            'port' => (int) $env('SMTP_PORT', 587),
            'encryption' => (string) $env('SMTP_ENCRYPTION', 'tls'),
            'username' => (string) $env('SMTP_USERNAME', ''),
            'password' => (string) $env('SMTP_PASSWORD', ''),
            'from_address' => (string) $env('SMTP_FROM_ADDRESS', 'nstructure@localhost'),
            'from_name' => (string) $env('SMTP_FROM_NAME', 'nStructure'),
        ],
    ];
};
