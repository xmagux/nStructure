<?php

declare(strict_types=1);

use NStructure\Http\Middleware\AuthMiddleware;
use NStructure\Http\Middleware\LocaleMiddleware;
use NStructure\Http\Middleware\CsrfMiddleware;
use NStructure\Http\Middleware\SecurityHeadersMiddleware;
use NStructure\Http\Middleware\SessionMiddleware;
use NStructure\Infrastructure\Container\Definitions;
use Slim\Factory\AppFactory;
use Symfony\Component\Dotenv\Dotenv;

$rootPath = dirname(__DIR__);

if (is_file($rootPath . '/.env')) {
    (new Dotenv())->usePutenv()->loadEnv($rootPath . '/.env');
}

$container = Definitions::build($rootPath);

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath('');

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(LocaleMiddleware::class);
$app->add(CsrfMiddleware::class);
$app->add(AuthMiddleware::class);
$app->add(SessionMiddleware::class);
$app->add(SecurityHeadersMiddleware::class);

$debug = filter_var((string) ($container->get('settings')['app']['debug'] ?? false), FILTER_VALIDATE_BOOL);
$app->addErrorMiddleware($debug, true, true);

(require $rootPath . '/routes/web.php')($app);
(require $rootPath . '/routes/api.php')($app);

return $app;
