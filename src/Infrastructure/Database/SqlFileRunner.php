<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Database;

use PDO;
use RuntimeException;

final class SqlFileRunner
{
    public static function run(PDO $pdo, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException(sprintf('Unable to read SQL file: %s', $path));
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
