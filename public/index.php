<?php

declare(strict_types=1);

use Slim\App;

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($requestPath) && is_file(__DIR__ . $requestPath)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var App $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->run();
