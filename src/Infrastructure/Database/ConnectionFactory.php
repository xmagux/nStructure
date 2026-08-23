<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Database;

use PDO;

final class ConnectionFactory
{
    public static function create(array $settings): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $settings['host'],
            $settings['port'],
            $settings['database'],
        );

        return new PDO($dsn, $settings['username'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_FOUND_ROWS => true,
        ]);
    }
}
