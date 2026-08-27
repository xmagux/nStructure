<?php

declare(strict_types=1);

use NStructure\Http\Controller\ApiController;
use NStructure\Http\Controller\AssetController;
use NStructure\Http\Controller\UserController;
use Slim\App;

return static function (App $app): void {
    $demoMode = (bool) $app->getContainer()?->get('settings')['app']['demo_mode'];
    $app->group('/api/v1', function ($group) use ($demoMode): void {
        $group->get('/health', [ApiController::class, 'health']);
        $group->get('/topology', [ApiController::class, 'topology']);
        $group->get('/search', [ApiController::class, 'search']);
        $group->get('/patch-panels/{id:[0-9]+}', [ApiController::class, 'panel']);
        $group->get('/patch-panel-ports/{id:[0-9]+}/rear-routes', [ApiController::class, 'rearFiberRoutes']);
        $group->get('/patch-panel-ports/{id:[0-9]+}/front-targets', [ApiController::class, 'frontPortTargets']);
        $group->get('/patch-panel-ports/{id:[0-9]+}/targets', [ApiController::class, 'connectionTargets']);
        $group->get('/fiber-paths/from-port/{id:[0-9]+}', [ApiController::class, 'tracePort']);
        $group->post('/locations', [ApiController::class, 'createLocation']);
        $group->post('/locations/{id:[0-9]+}', [ApiController::class, 'updateLocation']);
        $group->delete('/locations/{id:[0-9]+}', [ApiController::class, 'archiveLocation']);
        $group->post('/locations/{id:[0-9]+}/images', [AssetController::class, 'uploadLocationImage']);
        $group->post('/locations/{id:[0-9]+}/server-rooms', [ApiController::class, 'createServerRoom']);
        $group->post('/server-rooms/{id:[0-9]+}', [ApiController::class, 'updateServerRoom']);
        $group->delete('/server-rooms/{id:[0-9]+}', [ApiController::class, 'archiveServerRoom']);
        $group->post('/server-rooms/{id:[0-9]+}/images', [AssetController::class, 'uploadServerRoomImage']);
        $group->post('/server-rooms/{id:[0-9]+}/ups-devices', [ApiController::class, 'createUpsDevice']);
        $group->post('/ups-devices/{id:[0-9]+}', [ApiController::class, 'updateUpsDevice']);
        $group->delete('/ups-devices/{id:[0-9]+}', [ApiController::class, 'archiveUpsDevice']);
        $group->post('/server-rooms/{id:[0-9]+}/racks', [ApiController::class, 'createRack']);
        $group->post('/racks/{id:[0-9]+}', [ApiController::class, 'updateRack']);
        $group->delete('/racks/{id:[0-9]+}', [ApiController::class, 'archiveRack']);
        $group->post('/racks/{id:[0-9]+}/images', [AssetController::class, 'uploadRackImage']);
        $group->post('/racks/{id:[0-9]+}/patch-panels', [ApiController::class, 'createPatchPanel']);
        $group->post('/patch-panels/{id:[0-9]+}', [ApiController::class, 'updatePatchPanel']);
        $group->delete('/patch-panels/{id:[0-9]+}', [ApiController::class, 'archivePatchPanel']);
        $group->post('/patch-panels/{id:[0-9]+}/images', [AssetController::class, 'uploadPanelImage']);
        $group->post('/patch-panel-ports/{id:[0-9]+}', [ApiController::class, 'updatePort']);
        $group->post('/patch-panel-ports/{id:[0-9]+}/connections', [ApiController::class, 'connectPorts']);
        $group->post('/active-devices/{id:[0-9]+}', [ApiController::class, 'updateActiveDevice']);
        $group->delete('/active-devices/{id:[0-9]+}', [ApiController::class, 'archiveActiveDevice']);
        $group->post('/racks/{id:[0-9]+}/rack-items', [ApiController::class, 'createRackItem']);
        $group->post('/rack-items/{id:[0-9]+}', [ApiController::class, 'updateRackItem']);
        $group->delete('/rack-items/{id:[0-9]+}', [ApiController::class, 'archiveRackItem']);
        if (!$demoMode) {
            $group->post('/users', [UserController::class, 'create']);
            $group->delete('/users/{id:[0-9]+}', [UserController::class, 'archive']);
            $group->post('/account/password', [UserController::class, 'changePassword']);
            $group->post('/account/profile', [UserController::class, 'updateProfile']);
        }
        $group->post('/cables', [ApiController::class, 'createCable']);
        $group->post('/cables/{id:[0-9]+}', [ApiController::class, 'updateCable']);
        $group->delete('/cables/{id:[0-9]+}', [ApiController::class, 'archiveCable']);
        $group->post('/cables/{id:[0-9]+}/images', [AssetController::class, 'uploadCableImage']);
    });
};
