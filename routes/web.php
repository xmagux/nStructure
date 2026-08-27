<?php

declare(strict_types=1);

use NStructure\Http\Controller\AccountController;
use NStructure\Http\Controller\AuthController;
use NStructure\Http\Controller\DashboardController;
use NStructure\Http\Controller\AssetController;
use NStructure\Http\Controller\PageController;
use NStructure\Http\Controller\SensorController;
use Slim\App;

return static function (App $app): void {
    $demoMode = (bool) $app->getContainer()?->get('settings')['app']['demo_mode'];
    if (!$demoMode) {
        $app->get('/login', [AuthController::class, 'showLogin'])->setName('login');
        $app->post('/login', [AuthController::class, 'login']);
        $app->post('/logout', [AuthController::class, 'logout'])->setName('logout');
        $app->get('/account', [AccountController::class, 'show'])->setName('account');
        // Intentionally not linked from the main nav — visit directly.
        $app->get('/tools/sensors', [SensorController::class, 'index'])->setName('sensors');
    }
    $app->get('/', DashboardController::class)->setName('dashboard');
    $app->get('/topology', [PageController::class, 'topology'])->setName('topology');
    $app->get('/inventory', [PageController::class, 'inventory'])->setName('inventory');
    $app->get('/locations', [PageController::class, 'locations'])->setName('locations');
    $app->get('/locations/{id:[0-9]+}', [PageController::class, 'location'])->setName('location.show');
    $app->get('/racks/{id:[0-9]+}', [PageController::class, 'rack'])->setName('rack.show');
    $app->get('/patch-panels/{id:[0-9]+}', [PageController::class, 'panel'])->setName('panel.show');
    $app->get('/media/assets/{id:[0-9]+}', [AssetController::class, 'show'])->setName('asset.image');
    $app->get('/cables', [PageController::class, 'cables'])->setName('cables');
};
