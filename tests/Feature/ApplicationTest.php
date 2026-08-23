<?php

declare(strict_types=1);

namespace NStructure\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApplicationTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $_ENV['APP_DEMO_MODE'] = 'true';
        $_ENV['APP_LOCALE'] = 'en';
        $_SESSION = [];
        $this->app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
    }

    public function testDashboardRendersSuccessfully(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Your network, under control.', (string) $response->getBody());
        self::assertStringNotContainsString('data-compact="true"', (string) $response->getBody());
    }

    public function testTopologyAndPanelPagesRenderSuccessfully(): void
    {
        $factory = new ServerRequestFactory();
        $topology = $this->app->handle($factory->createServerRequest('GET', '/topology'));
        $rack = $this->app->handle($factory->createServerRequest('GET', '/racks/1'));
        $panel = $this->app->handle($factory->createServerRequest('GET', '/patch-panels/3'));

        self::assertSame(200, $topology->getStatusCode());
        self::assertStringContainsString('Physical network map', (string) $topology->getBody());
        self::assertStringContainsString('data-cytoscape', (string) $topology->getBody());
        self::assertStringContainsString('/assets/vendor/cytoscape.min.js', (string) $topology->getBody());
        self::assertSame(200, $rack->getStatusCode());
        self::assertStringContainsString('data-rack-canvas', (string) $rack->getBody());
        self::assertStringContainsString('href="/locations/1"', (string) $rack->getBody());
        self::assertStringContainsString('data-no-destination=', (string) $rack->getBody());
        self::assertStringContainsString('action="/api/v1/racks/1/images"', (string) $rack->getBody());
        self::assertSame(200, $panel->getStatusCode());
        self::assertStringContainsString('12 loose fibers', (string) $panel->getBody());
        self::assertStringContainsString('data-panel-canvas', (string) $panel->getBody());
        self::assertStringContainsString('href="/racks/3"', (string) $panel->getBody());
        self::assertStringContainsString('action="/api/v1/patch-panels/3/images"', (string) $panel->getBody());
        self::assertStringNotContainsString('ports-grid', (string) $panel->getBody());
    }

    public function testInventoryRendersInteractiveHierarchy(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/inventory');
        $response = $this->app->handle($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Infrastructure model', $body);
        self::assertStringContainsString('data-graph-mode="inventory"', $body);
        self::assertStringContainsString('inventory-location-1', $body);
        self::assertStringContainsString('\/patch-panels\/1', $body);
    }

    public function testHealthApiReturnsJson(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/health');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","service":"nStructure"}',
            (string) $response->getBody(),
        );
    }

    public function testLocationCreationRequiresCsrfAndPersistsInDemoSession(): void
    {
        $factory = new ServerRequestFactory();
        $this->app->handle($factory->createServerRequest('GET', '/locations'));

        $request = $factory->createServerRequest('POST', '/api/v1/locations')
            ->withHeader('X-CSRF-Token', (string) $_SESSION['csrf_token'])
            ->withParsedBody([
                'code' => 'WAW-EAST',
                'name' => 'East Operations Center',
                'address' => 'Warsaw',
            ]);
        $response = $this->app->handle($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('WAW-EAST', (string) $response->getBody());
        self::assertCount(1, $_SESSION['demo_locations']);
    }

    public function testRoomRackAndCableCreationPersistInDemoSession(): void
    {
        $factory = new ServerRequestFactory();
        $this->app->handle($factory->createServerRequest('GET', '/locations/1'));
        $token = (string) $_SESSION['csrf_token'];

        $roomResponse = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/locations/1/server-rooms')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody(['location_id' => 1, 'name' => 'NOC Room', 'floor' => '2']),
        );
        self::assertSame(201, $roomResponse->getStatusCode());
        self::assertStringContainsString('SR-001', (string) $roomResponse->getBody());

        $rackResponse = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/server-rooms/200/racks')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody(['code' => 'R-NOC-01', 'name' => 'NOC Rack', 'total_units' => 42, 'row_label' => 'N']),
        );
        self::assertSame(201, $rackResponse->getStatusCode());

        $cableResponse = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/cables')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody([
                    'code' => 'CBL-NEW-001',
                    'name' => 'New physical route',
                    'medium' => 'SM',
                    'fiber_count' => 24,
                    'source_location_id' => 1,
                    'destination_location_id' => 2,
                    'length_m' => 1250,
                    'operational_status' => 'PLANNED',
                ]),
        );
        self::assertSame(201, $cableResponse->getStatusCode());

        $internalCableResponse = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/cables')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody([
                    'code' => 'CBL-INTERNAL-001',
                    'name' => 'Same-building rack route',
                    'medium' => 'SM',
                    'fiber_count' => 12,
                    'source_endpoint' => 'RACK:1',
                    'destination_endpoint' => 'RACK:300',
                    'length_m' => 80,
                    'operational_status' => 'ACTIVE',
                ]),
        );
        self::assertSame(201, $internalCableResponse->getStatusCode());
        self::assertStringContainsString('RACK:300', (string) $internalCableResponse->getBody());

        $location = $this->app->handle($factory->createServerRequest('GET', '/locations/1'));
        self::assertStringContainsString('NOC Room', (string) $location->getBody());
        self::assertStringContainsString('NOC Rack', (string) $location->getBody());
        self::assertStringContainsString('data-room-location', (string) $location->getBody());

        $topology = $this->app->handle($factory->createServerRequest('GET', '/topology'));
        self::assertStringContainsString('CBL-NEW-001', (string) $topology->getBody());
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/locations')
            ->withHeader('X-CSRF-Token', 'invalid')
            ->withParsedBody(['code' => 'WAW-X', 'name' => 'Invalid']);
        $response = $this->app->handle($request);

        self::assertSame(419, $response->getStatusCode());
    }

    public function testFiberPathApiTracesAcrossTheSpliceClosure(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/fiber-paths/from-port/97');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Metro Splice M01', (string) $response->getBody());
        self::assertStringContainsString('PP-WAW-01', (string) $response->getBody());
    }

    public function testSearchApiFindsAStoredPatchPanel(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/search?q=PP-WAW');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('PP-WAW-01', (string) $response->getBody());
        self::assertStringContainsString('/patch-panels/1', (string) $response->getBody());
    }

    public function testPatchPanelPortDescriptionAndConnectionWorkflow(): void
    {
        $factory = new ServerRequestFactory();
        $this->app->handle($factory->createServerRequest('GET', '/racks/1'));
        $token = (string) $_SESSION['csrf_token'];
        $panelInput = [
            'name' => 'Customer Distribution A',
            'rack_unit_start' => 20,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'connector_type_id' => 4,
        ];
        $firstResponse = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/racks/1/patch-panels')->withHeader('X-CSRF-Token', $token)->withParsedBody($panelInput),
        );
        $secondResponse = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/racks/2/patch-panels')->withHeader('X-CSRF-Token', $token)->withParsedBody(array_merge($panelInput, ['name' => 'Customer Distribution B'])),
        );
        self::assertSame(201, $firstResponse->getStatusCode());
        self::assertSame(201, $secondResponse->getStatusCode());
        $firstPanelId = (int) json_decode((string) $firstResponse->getBody(), true)['data']['id'];
        $secondPanelId = (int) json_decode((string) $secondResponse->getBody(), true)['data']['id'];
        $repository = new \NStructure\Infrastructure\Repository\DemoNetworkRepository();
        $sourcePortId = (int) $repository->panel($firstPanelId)['port_items'][0]['id'];
        $destinationPortId = (int) $repository->panel($secondPanelId)['port_items'][0]['id'];

        $updateResponse = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/patch-panel-ports/' . $sourcePortId)
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody(['connector_type_id' => 4, 'label' => 'Customer circuit', 'remote_endpoint_label' => 'Customer room · Rack C01', 'administrative_status' => 'AVAILABLE', 'notes' => 'Primary path']),
        );
        self::assertSame(200, $updateResponse->getStatusCode());

        $routeResponse = $this->app->handle($factory->createServerRequest('GET', '/api/v1/patch-panel-ports/' . $sourcePortId . '/rear-routes'));
        self::assertSame(200, $routeResponse->getStatusCode());
        $routes = json_decode((string) $routeResponse->getBody(), true)['data'];
        $route = array_values(array_filter($routes, static fn (array $item): bool => $item['selectable'] && $item['destination_location_id'] === 2))[0];

        $targetResponse = $this->app->handle($factory->createServerRequest('GET', '/api/v1/patch-panel-ports/' . $sourcePortId . '/targets?q=Distribution&route=' . $route['key']));
        self::assertSame(200, $targetResponse->getStatusCode());
        self::assertStringContainsString((string) $destinationPortId, (string) $targetResponse->getBody());

        $connectionResponse = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/patch-panel-ports/' . $sourcePortId . '/connections')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody(['destination_port_id' => $destinationPortId, 'rear_route_key' => $route['key'], 'notes' => 'Documented physical route']),
        );
        self::assertSame(201, $connectionResponse->getStatusCode());
        self::assertStringContainsString('RFC-000001', (string) $connectionResponse->getBody());

        $panelPage = $this->app->handle($factory->createServerRequest('GET', '/patch-panels/' . $firstPanelId));
        self::assertSame(200, $panelPage->getStatusCode());
        self::assertStringContainsString('Customer circuit', (string) $panelPage->getBody());
    }

    public function testFrontConnectionCanTargetAnotherRackPort(): void
    {
        $factory = new ServerRequestFactory();
        $this->app->handle($factory->createServerRequest('GET', '/racks/1'));
        $token = (string) $_SESSION['csrf_token'];
        $panelInput = [
            'name' => 'Front Link Panel',
            'rack_unit_start' => 17,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'connector_type_id' => 4,
        ];
        $firstResponse = $this->app->handle($factory->createServerRequest('POST', '/api/v1/racks/1/patch-panels')->withHeader('X-CSRF-Token', $token)->withParsedBody($panelInput));
        $secondResponse = $this->app->handle($factory->createServerRequest('POST', '/api/v1/racks/2/patch-panels')->withHeader('X-CSRF-Token', $token)->withParsedBody(array_replace($panelInput, ['name' => 'Remote Front Link Panel'])));
        $repository = new \NStructure\Infrastructure\Repository\DemoNetworkRepository();
        $sourcePanelId = (int) json_decode((string) $firstResponse->getBody(), true)['data']['id'];
        $destinationPanelId = (int) json_decode((string) $secondResponse->getBody(), true)['data']['id'];
        $sourcePortId = (int) $repository->panel($sourcePanelId)['port_items'][0]['id'];
        $destinationPortId = (int) $repository->panel($destinationPanelId)['port_items'][0]['id'];

        $targets = $this->app->handle($factory->createServerRequest('GET', '/api/v1/patch-panel-ports/' . $sourcePortId . '/front-targets?q=Remote'));
        self::assertSame(200, $targets->getStatusCode());
        self::assertStringContainsString((string) $destinationPortId, (string) $targets->getBody());

        $update = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/patch-panel-ports/' . $sourcePortId)
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody([
                    'connector_type_id' => 4,
                    'label' => 'Front inter-rack link',
                    'administrative_status' => 'AVAILABLE',
                    'notes' => '',
                    'front_connection_mode' => 'PORT',
                    'front_destination_port_id' => $destinationPortId,
                    'front_patch_cord_label' => 'PC-FRONT-001',
                    'front_connection_notes' => 'Inter-rack front patch cord',
                ]),
        );
        self::assertSame(200, $update->getStatusCode());
        self::assertSame('PORT', $repository->panel($sourcePanelId)['port_items'][0]['front_connection']['type']);
    }

    public function testEditControlsAndRackPortCountsRender(): void
    {
        $factory = new ServerRequestFactory();
        $location = $this->app->handle($factory->createServerRequest('GET', '/locations/1'));
        $rack = $this->app->handle($factory->createServerRequest('GET', '/racks/1'));
        $panel = $this->app->handle($factory->createServerRequest('GET', '/patch-panels/1'));
        $cables = $this->app->handle($factory->createServerRequest('GET', '/cables'));

        self::assertStringContainsString('data-location-edit-open', (string) $location->getBody());
        self::assertStringContainsString('data-room-edit-open', (string) $location->getBody());
        self::assertStringContainsString('Rack code', (string) $location->getBody());
        self::assertStringContainsString('Rack name', (string) $location->getBody());
        self::assertStringContainsString('data-rack-edit-open', (string) $rack->getBody());
        self::assertStringContainsString('48/48', (string) $rack->getBody());
        self::assertStringContainsString('data-panel-edit-open', (string) $panel->getBody());
        self::assertStringContainsString('Patch panel code', (string) $panel->getBody());
        self::assertStringContainsString('data-port-management', (string) $panel->getBody());
        self::assertStringContainsString('data-port-inline-edit-form', (string) $panel->getBody());
        self::assertStringContainsString('name="connector_type_id"', (string) $panel->getBody());
        self::assertStringNotContainsString('name="remote_endpoint_label"', (string) $panel->getBody());
        self::assertStringContainsString('name="front_connection_mode"', (string) $panel->getBody());
        self::assertStringContainsString('value="PORT"', (string) $panel->getBody());
        self::assertStringContainsString('name="front_destination_port_id"', (string) $panel->getBody());
        self::assertStringContainsString('name="active_device_id"', (string) $panel->getBody());
        self::assertStringContainsString('Juniper', (string) $panel->getBody());
        self::assertStringContainsString('Palo Alto Networks', (string) $panel->getBody());
        self::assertStringContainsString('Fortinet', (string) $panel->getBody());
        self::assertStringNotContainsString('canvas-connection-map', (string) $panel->getBody());
        self::assertStringContainsString('data-panel-port-search', (string) $panel->getBody());
        self::assertStringContainsString('data-port-edit-open data-port-id=', (string) $panel->getBody());
        self::assertStringContainsString('data-port-connect-open data-port-id=', (string) $panel->getBody());
        self::assertStringContainsString('name="rear_route_key"', (string) $panel->getBody());
        self::assertStringContainsString('data-rear-route-select', (string) $panel->getBody());
        self::assertStringContainsString('data-cable-edit-open', (string) $cables->getBody());
        self::assertStringContainsString('Cable code', (string) $cables->getBody());
        self::assertStringContainsString('Cable name', (string) $cables->getBody());
        self::assertStringContainsString('Physical end A', (string) $cables->getBody());
        self::assertStringContainsString('name="source_endpoint"', (string) $cables->getBody());
        self::assertStringContainsString('value="RACK:1"', (string) $cables->getBody());
        self::assertStringContainsString('class="cable-route"', (string) $cables->getBody());
        self::assertStringContainsString('data-sidebar-toggle', (string) $rack->getBody());
    }

    public function testEntityUpdateApisPersistChangesInDemoMode(): void
    {
        $factory = new ServerRequestFactory();
        $this->app->handle($factory->createServerRequest('GET', '/locations/1'));
        $token = (string) $_SESSION['csrf_token'];
        $requests = [
            $factory->createServerRequest('POST', '/api/v1/locations/1')->withHeader('X-CSRF-Token', $token)->withParsedBody(['code' => 'WAW-EDIT', 'name' => 'Edited Location', 'address' => 'Edited address']),
            $factory->createServerRequest('POST', '/api/v1/server-rooms/1')->withHeader('X-CSRF-Token', $token)->withParsedBody(['location_id' => 1, 'name' => 'Edited Room', 'floor' => '4']),
            $factory->createServerRequest('POST', '/api/v1/racks/1')->withHeader('X-CSRF-Token', $token)->withParsedBody(['code' => 'R-EDIT', 'name' => 'Edited Rack', 'total_units' => 45, 'row_label' => 'Z']),
            $factory->createServerRequest('POST', '/api/v1/patch-panels/1')->withHeader('X-CSRF-Token', $token)->withParsedBody(['code' => 'PP-EDIT-01', 'name' => 'Edited Panel', 'rack_unit_start' => 41, 'rack_unit_height' => 2, 'port_count' => 48, 'layout_rows' => 2, 'connector_type_id' => 4, 'manufacturer' => 'Vendor', 'model' => 'Model']),
            $factory->createServerRequest('POST', '/api/v1/cables/1')->withHeader('X-CSRF-Token', $token)->withParsedBody(['code' => 'CBL-EDIT-01', 'name' => 'Edited Cable', 'medium' => 'SM', 'fiber_count' => 48, 'source_location_id' => 1, 'destination_location_id' => 2, 'length_m' => 7000, 'operational_status' => 'ACTIVE']),
        ];
        foreach ($requests as $request) {
            self::assertSame(200, $this->app->handle($request)->getStatusCode());
        }

        self::assertStringContainsString('Edited Location', (string) $this->app->handle($factory->createServerRequest('GET', '/locations/1'))->getBody());
        self::assertStringContainsString('Edited Rack', (string) $this->app->handle($factory->createServerRequest('GET', '/racks/1'))->getBody());
        self::assertStringContainsString('Edited Panel', (string) $this->app->handle($factory->createServerRequest('GET', '/patch-panels/1'))->getBody());
        self::assertStringContainsString('Edited Cable', (string) $this->app->handle($factory->createServerRequest('GET', '/cables'))->getBody());
    }

    public function testGuardedDeleteApiArchivesEmptyElementsAndBlocksConnectedOnes(): void
    {
        $factory = new ServerRequestFactory();
        $locationPage = $this->app->handle($factory->createServerRequest('GET', '/locations/1'));
        $token = (string) $_SESSION['csrf_token'];
        self::assertStringContainsString('data-delete-open', (string) $locationPage->getBody());
        self::assertStringContainsString('id="delete-confirm-modal"', (string) $locationPage->getBody());

        $blocked = $this->app->handle(
            $factory->createServerRequest('DELETE', '/api/v1/locations/1')->withHeader('X-CSRF-Token', $token),
        );
        self::assertSame(409, $blocked->getStatusCode());
        self::assertStringContainsString('location_has_rooms', (string) $blocked->getBody());

        $created = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/locations')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody(['code' => 'DELETE-ME', 'name' => 'Temporary empty site', 'address' => '']),
        );
        $locationId = (int) json_decode((string) $created->getBody(), true)['data']['id'];
        $archived = $this->app->handle(
            $factory->createServerRequest('DELETE', '/api/v1/locations/' . $locationId)->withHeader('X-CSRF-Token', $token),
        );
        self::assertSame(200, $archived->getStatusCode());
        self::assertStringContainsString('"archived":true', (string) $archived->getBody());
        self::assertSame(404, $this->app->handle($factory->createServerRequest('GET', '/locations/' . $locationId))->getStatusCode());
    }

    public function testUpsApiAndServerRoomInventoryWorkflow(): void
    {
        $factory = new ServerRequestFactory();
        $initialPage = $this->app->handle($factory->createServerRequest('GET', '/locations/1'));
        $token = (string) $_SESSION['csrf_token'];
        self::assertStringContainsString('data-ups-modal-open', (string) $initialPage->getBody());

        $created = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/server-rooms/1/ups-devices')
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody([
                    'name' => 'Core UPS A',
                    'manufacturer' => 'Eaton',
                    'model' => '9PX 6000i',
                    'serial_number' => 'EATON-01',
                    'rated_power_va' => 6000,
                    'rated_power_w' => 5400,
                    'ip_address' => '192.0.2.20',
                    'management_url' => 'https://192.0.2.20',
                    'battery_replaced_at' => '2025-06-15',
                    'battery_replacement_interval_months' => 36,
                    'operational_status' => 'ACTIVE',
                    'notes' => 'Main room supply',
                ]),
        );
        self::assertSame(201, $created->getStatusCode());
        $upsDeviceId = (int) json_decode((string) $created->getBody(), true)['data']['id'];

        $page = $this->app->handle($factory->createServerRequest('GET', '/locations/1'));
        self::assertStringContainsString('Core UPS A', (string) $page->getBody());
        self::assertStringContainsString('https://192.0.2.20', (string) $page->getBody());
        self::assertStringContainsString('data-ups-edit-open', (string) $page->getBody());

        $updated = $this->app->handle(
            $factory->createServerRequest('POST', '/api/v1/ups-devices/' . $upsDeviceId)
                ->withHeader('X-CSRF-Token', $token)
                ->withParsedBody([
                    'name' => 'Core UPS A serviced',
                    'manufacturer' => 'Eaton',
                    'model' => '9PX 6000i',
                    'serial_number' => 'EATON-01',
                    'rated_power_va' => 6000,
                    'rated_power_w' => 5400,
                    'ip_address' => '192.0.2.20',
                    'management_url' => 'https://192.0.2.20',
                    'battery_replaced_at' => '2026-08-01',
                    'battery_replacement_interval_months' => 36,
                    'operational_status' => 'MAINTENANCE',
                    'notes' => 'Service in progress',
                ]),
        );
        self::assertSame(200, $updated->getStatusCode());
        self::assertStringContainsString('Core UPS A serviced', (string) $updated->getBody());

        $removed = $this->app->handle(
            $factory->createServerRequest('DELETE', '/api/v1/ups-devices/' . $upsDeviceId)
                ->withHeader('X-CSRF-Token', $token),
        );
        self::assertSame(200, $removed->getStatusCode());
        self::assertStringContainsString('"archived":true', (string) $removed->getBody());
    }

    public function testDamagedPatchPanelPortHasAVisualRedRingRule(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.js');

        self::assertIsString($script);
        self::assertStringContainsString("const damaged = portStatus === 'damaged'", $script);
        self::assertStringContainsString('stroke: damaged ? palette.red', $script);
        self::assertStringContainsString('shadowColor: damaged ? palette.red', $script);
    }
}
