<?php

declare(strict_types=1);

use NStructure\Infrastructure\Database\ConnectionFactory;
use NStructure\Infrastructure\Repository\MySqlUserRepository;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$rootPath = dirname(__DIR__);
if (is_file($rootPath . '/.env')) {
    (new Dotenv())->usePutenv()->loadEnv($rootPath . '/.env');
}

$email = $argv[1] ?? null;
$name = $argv[2] ?? null;
$password = $argv[3] ?? null;

if (!$email || !$name || !$password) {
    fwrite(STDERR, "Usage: php bin/create-user.php <email> \"<name>\" <password>\n");
    exit(2);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Provide a valid email address.\n");
    exit(2);
}

if (mb_strlen($password) < 8) {
    fwrite(STDERR, "Password must contain at least 8 characters.\n");
    exit(2);
}

$settingsFactory = require $rootPath . '/config/settings.php';
$settings = $settingsFactory($rootPath);
$pdo = ConnectionFactory::create($settings['database']);
$repository = new MySqlUserRepository($pdo);

$user = $repository->create(['email' => $email, 'name' => $name, 'password' => $password]);
fwrite(STDOUT, "User created: {$user['email']} (id {$user['id']})\n");
