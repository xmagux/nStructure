<?php

declare(strict_types=1);

namespace NStructure\Tests\Unit;

use NStructure\Domain\Exception\ResourceInUseException;
use NStructure\Infrastructure\Repository\DemoNetworkRepository;
use PHPUnit\Framework\TestCase;

final class DemoNetworkRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testBranchTopologyPreservesSegmentCapacity(): void
    {
        $topology = (new DemoNetworkRepository())->topology();

        self::assertCount(4, $topology['nodes']);
        self::assertCount(3, $topology['edges']);
        self::assertSame([48, 24, 24], array_column($topology['edges'], 'fibers'));
        self::assertSame('splice', $topology['nodes'][1]['type']);
    }

    public function testPartialTerminationKeepsLooseFibersVisible(): void
    {
        $panel = (new DemoNetworkRepository())->panel(3);

        self::assertNotNull($panel);
        self::assertSame(24, $panel['ports']);
        self::assertSame(12, $panel['occupied']);
        self::assertSame(12, $panel['unterminated']);
        self::assertSame('available', $panel['port_items'][23]['status']);
    }

    public function testRackElevationIncludesIndividualPatchPanelPortStates(): void
    {
        $rack = (new DemoNetworkRepository())->rack(1);

        self::assertNotNull($rack);
        self::assertCount(48, $rack['devices'][0]['port_items']);
        self::assertSame(2, $rack['devices'][0]['rows']);
        self::assertSame('occupied', $rack['devices'][0]['port_items'][0]['status']);
        self::assertStringContainsString('North Office', $rack['devices'][0]['port_items'][0]['destination']);
        self::assertCount(24, $rack['devices'][2]['port_items']);
        self::assertSame('available', $rack['devices'][2]['port_items'][23]['status']);
    }

    public function testInventoryPreservesThePhysicalHierarchy(): void
    {
        $inventory = (new DemoNetworkRepository())->inventory();

        self::assertSame(['locations' => 3, 'rooms' => 4, 'racks' => 9, 'panels' => 14], $inventory['summary']);
        self::assertCount(20, $inventory['nodes']);
        self::assertCount(17, $inventory['edges']);
        self::assertContains('location', array_column($inventory['nodes'], 'type'));
        self::assertContains('room', array_column($inventory['nodes'], 'type'));
        self::assertContains('rack', array_column($inventory['nodes'], 'type'));
        self::assertContains('panel', array_column($inventory['nodes'], 'type'));
    }

    public function testDemoLocationIsStoredInTheSession(): void
    {
        $repository = new DemoNetworkRepository();
        $created = $repository->createLocation([
            'code' => 'waw-east',
            'name' => 'East Operations Center',
            'address' => 'Warsaw',
        ]);

        self::assertSame('WAW-EAST', $created['code']);
        self::assertCount(4, $repository->locations());
    }

    public function testDemoRoomRackAndCableAreStoredInTheSession(): void
    {
        $repository = new DemoNetworkRepository();
        $room = $repository->createServerRoom(1, ['name' => 'Room X', 'floor' => '3']);
        $rack = $repository->createRack($room['id'], ['code' => 'RX1', 'name' => 'Rack X1', 'total_units' => 42]);
        $cable = $repository->createCable([
            'code' => 'CBL-X',
            'name' => 'Cable X',
            'medium' => 'SM',
            'fiber_count' => 12,
            'source_location_id' => 1,
            'destination_location_id' => 2,
            'length_m' => 500,
            'operational_status' => 'PLANNED',
        ]);

        self::assertSame('Room X', $repository->location(1)['rooms_detail'][2]['name']);
        self::assertSame('SR-001', $room['code']);
        self::assertSame('Rack X1', $repository->rack($rack['id'])['name']);
        self::assertSame('Cable X', $cable['name']);
        self::assertSame(4, $repository->topology()['summary']['segments']);
    }

    public function testRearCableCanConnectRacksInsideOneServerRoom(): void
    {
        $repository = new DemoNetworkRepository();
        $firstRack = $repository->createRack(1, ['code' => 'RA1', 'name' => 'Internal Rack A', 'total_units' => 42]);
        $secondRack = $repository->createRack(1, ['code' => 'RB1', 'name' => 'Internal Rack B', 'total_units' => 42]);
        $panelInput = [
            'name' => 'Internal Fiber Panel',
            'rack_unit_start' => 42,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 4,
        ];
        $sourcePanel = $repository->createPatchPanel($firstRack['id'], $panelInput);
        $destinationPanel = $repository->createPatchPanel($secondRack['id'], $panelInput);
        $outsidePanel = $repository->createPatchPanel(1, array_replace($panelInput, ['rack_unit_start' => 20]));
        $cable = $repository->createCable([
            'code' => 'CBL-INTERNAL-01',
            'name' => 'Internal rack link',
            'medium' => 'SM',
            'fiber_count' => 12,
            'source_endpoint' => 'RACK:' . $firstRack['id'],
            'destination_endpoint' => 'RACK:' . $secondRack['id'],
            'length_m' => 35,
            'operational_status' => 'ACTIVE',
        ]);
        $sourcePortId = (int) $repository->panel($sourcePanel['id'])['port_items'][0]['id'];
        $destinationPortId = (int) $repository->panel($destinationPanel['id'])['port_items'][0]['id'];
        $outsidePortId = (int) $repository->panel($outsidePanel['id'])['port_items'][0]['id'];

        $routes = array_values(array_filter(
            $repository->rearFiberRoutes($sourcePortId),
            static fn (array $route): bool => $route['cable_path'] === 'CBL-INTERNAL-01',
        ));
        $outsideRoutes = array_filter(
            $repository->rearFiberRoutes($outsidePortId),
            static fn (array $route): bool => $route['cable_path'] === 'CBL-INTERNAL-01',
        );
        self::assertSame('RACK:' . $firstRack['id'], $cable['source_endpoint_key']);
        self::assertCount(1, $routes);
        self::assertSame($secondRack['id'], $routes[0]['destination_rack_id']);
        self::assertSame([], $outsideRoutes);
        self::assertSame($destinationPortId, $repository->connectionTargets($sourcePortId, '', $routes[0]['key'])[0]['id']);
    }

    public function testPortTraceCrossesTheBranchClosure(): void
    {
        $path = (new DemoNetworkRepository())->tracePort(97);

        self::assertNotNull($path);
        self::assertSame('complete', $path['status']);
        self::assertCount(5, $path['steps']);
        self::assertSame('splice', $path['steps'][2]['type']);
        self::assertStringContainsString('PP-WAW-01', $path['steps'][4]['label']);
    }

    public function testPatchPanelsPortsAndPatchCordsPersistInTheSession(): void
    {
        $repository = new DemoNetworkRepository();
        $first = $repository->createPatchPanel(1, [
            'name' => 'Customer Distribution A',
            'rack_unit_start' => 20,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 4,
        ]);
        $second = $repository->createPatchPanel(2, [
            'name' => 'Customer Distribution B',
            'rack_unit_start' => 20,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 3,
        ]);
        $sourcePortId = (int) $repository->panel($first['id'])['port_items'][0]['id'];
        $destinationPortId = (int) $repository->panel($second['id'])['port_items'][0]['id'];

        $repository->updatePort($sourcePortId, ['label' => 'Customer circuit', 'administrative_status' => 'AVAILABLE', 'notes' => 'Primary path']);
        $routes = array_values(array_filter($repository->rearFiberRoutes($sourcePortId), static fn (array $route): bool => $route['selectable'] && $route['destination_location_id'] === 2));
        self::assertNotEmpty($routes);
        $targets = $repository->connectionTargets($sourcePortId, 'PP-R2-001', $routes[0]['key']);
        $connection = $repository->connectPorts($sourcePortId, $destinationPortId, ['rear_route_key' => $routes[0]['key'], 'notes' => 'Physical fiber assignment']);

        self::assertSame('Customer circuit', $repository->panel($first['id'])['port_items'][0]['label']);
        self::assertSame($destinationPortId, $targets[0]['id']);
        self::assertSame('RFC-000001', $connection['code']);
        self::assertStringContainsString('CBL-WAW-', $repository->panel($first['id'])['port_items'][0]['rear_destination']);
        self::assertTrue($repository->panel($first['id'])['port_items'][0]['has_patch_cord']);
    }

    public function testDestinationPortsRequireAnAvailablePhysicalRoute(): void
    {
        $repository = new DemoNetworkRepository();
        $panel = $repository->createPatchPanel(1, [
            'name' => 'Route Validation Panel',
            'rack_unit_start' => 16,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 4,
        ]);
        $sourcePortId = (int) $repository->panel($panel['id'])['port_items'][0]['id'];

        self::assertSame([], $repository->connectionTargets($sourcePortId, ''));
        self::assertNotEmpty(array_filter($repository->rearFiberRoutes($sourcePortId), static fn (array $route): bool => $route['selectable']));

        $_SESSION['demo_rear_route_usage'][1] = 6;
        $_SESSION['demo_rear_route_usage'][2] = 10;
        $fullRoutes = $repository->rearFiberRoutes($sourcePortId);
        self::assertNotEmpty(array_filter($fullRoutes, static fn (array $route): bool => $route['availability'] === 'full' && !$route['selectable']));
        self::assertSame([], array_filter($fullRoutes, static fn (array $route): bool => $route['selectable']));
        self::assertSame([], $repository->connectionTargets($sourcePortId, '', $fullRoutes[0]['key']));
    }

    public function testPatchPanelPortStoresIndependentRearAndActiveDeviceFrontConnections(): void
    {
        $repository = new DemoNetworkRepository();
        $panel = $repository->createPatchPanel(1, [
            'name' => 'Security Distribution',
            'rack_unit_start' => 18,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 4,
        ]);
        $rearPanel = $repository->createPatchPanel(2, [
            'name' => 'Security Remote Distribution',
            'rack_unit_start' => 18,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 4,
        ]);
        $portId = (int) $repository->panel($panel['id'])['port_items'][0]['id'];
        $rearPortId = (int) $repository->panel($rearPanel['id'])['port_items'][0]['id'];
        $route = array_values(array_filter($repository->rearFiberRoutes($portId), static fn (array $item): bool => $item['selectable'] && $item['destination_location_id'] === 2))[0];
        $repository->connectPorts($portId, $rearPortId, ['rear_route_key' => $route['key'], 'notes' => 'Security backbone']);

        $repository->updatePort($portId, [
            'connector_type_id' => 4,
            'label' => 'Firewall uplink',
            'administrative_status' => 'AVAILABLE',
            'notes' => 'Redundant path',
            'front_connection_mode' => 'DEVICE',
            'active_device_id' => 0,
            'active_device_rack_id' => 1,
            'active_device_type' => 'FIREWALL',
            'active_device_vendor' => 'Palo Alto Networks',
            'active_device_name' => 'Core Firewall 01',
            'active_device_model' => 'PA-3410',
            'active_interface_name' => 'ethernet1/1',
            'active_interface_type' => 'SFP_PLUS',
            'active_interface_speed' => '10G',
            'front_patch_cord_label' => 'PC-LC-001',
            'front_connection_notes' => 'Untrust zone',
        ]);

        $port = $repository->panel($panel['id'])['port_items'][0];
        self::assertStringContainsString('CBL-WAW-', $port['rear_destination']);
        self::assertStringContainsString('Palo Alto Networks', $port['front_destination']);
        self::assertSame('ethernet1/1', $port['front_connection']['interface_name']);
        self::assertSame('DEVICE', $port['front_connection']['type']);
        self::assertTrue($port['has_front_connection']);
        self::assertSame('occupied', $port['status']);
    }

    public function testFrontPortCanConnectToAPatchPanelInAnotherRack(): void
    {
        $repository = new DemoNetworkRepository();
        $first = $repository->createPatchPanel(1, [
            'name' => 'Front Source Panel',
            'rack_unit_start' => 15,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 4,
        ]);
        $second = $repository->createPatchPanel(2, [
            'name' => 'Front Destination Panel',
            'rack_unit_start' => 15,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 4,
        ]);
        $sourcePortId = (int) $repository->panel($first['id'])['port_items'][0]['id'];
        $destinationPortId = (int) $repository->panel($second['id'])['port_items'][0]['id'];

        $targets = $repository->frontPortTargets($sourcePortId, 'Front Destination');
        self::assertSame($destinationPortId, $targets[0]['id']);
        $repository->updatePort($sourcePortId, [
            'connector_type_id' => 4,
            'label' => 'Inter-rack jumper',
            'administrative_status' => 'AVAILABLE',
            'notes' => null,
            'front_connection_mode' => 'PORT',
            'front_destination_port_id' => $destinationPortId,
            'front_patch_cord_label' => 'PC-LC-R1-R2',
            'front_connection_notes' => 'Front jumper',
        ]);

        $source = $repository->panel($first['id'])['port_items'][0];
        $destination = $repository->panel($second['id'])['port_items'][0];
        self::assertSame('PORT', $source['front_connection']['type']);
        self::assertSame($destinationPortId, $source['front_connection']['destination_port_id']);
        self::assertStringContainsString($repository->panel($second['id'])['code'], $source['front_destination']);
        self::assertStringContainsString($repository->panel($first['id'])['code'], $destination['front_destination']);
        self::assertTrue($destination['has_front_connection']);
    }

    public function testSearchReturnsPhysicalAssets(): void
    {
        $results = (new DemoNetworkRepository())->search('PP-WAW');

        self::assertNotEmpty($results);
        self::assertSame('panel', $results[0]['type']);
        self::assertSame('/patch-panels/1', $results[0]['href']);
    }

    public function testAssetImagesAreAttachedToTheirInfrastructureEntity(): void
    {
        $repository = new DemoNetworkRepository();
        $image = $repository->addAssetImage('RACK', 1, [
            'storage_path' => str_repeat('a', 40) . '.jpg',
            'original_name' => 'rack-front.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'width_px' => 1600,
            'height_px' => 1000,
        ]);

        self::assertSame('/media/assets/' . $image['id'], $image['url']);
        self::assertSame('rack-front.jpg', $repository->rack(1)['images'][0]['original_name']);
        self::assertSame($image, $repository->assetImage($image['id']));
        self::assertSame([], $repository->panel(1)['images']);
    }

    public function testEmptyInfrastructureElementsCanBeArchived(): void
    {
        $repository = new DemoNetworkRepository();
        $location = $repository->createLocation(['code' => 'EMPTY-DC', 'name' => 'Empty site', 'address' => '']);
        $rack = $repository->createRack(1, ['code' => 'EMPTY-R', 'name' => 'Empty rack', 'total_units' => 42]);
        $panel = $repository->createPatchPanel(1, [
            'name' => 'Empty panel',
            'rack_unit_start' => 14,
            'rack_unit_height' => 1,
            'port_count' => 12,
            'layout_rows' => 1,
            'layout_columns' => 12,
            'connector_type_id' => 4,
        ]);
        $cable = $repository->createCable([
            'code' => 'EMPTY-CBL',
            'name' => 'Unused cable',
            'medium' => 'SM',
            'fiber_count' => 12,
            'source_location_id' => 1,
            'destination_location_id' => 2,
            'length_m' => 100,
            'operational_status' => 'PLANNED',
        ]);

        self::assertTrue($repository->archivePatchPanel($panel['id'])['archived']);
        self::assertNull($repository->panel($panel['id']));
        self::assertTrue($repository->archiveRack($rack['id'])['archived']);
        self::assertNull($repository->rack($rack['id']));
        self::assertTrue($repository->archiveCable($cable['id'])['archived']);
        self::assertTrue($repository->archiveLocation($location['id'])['archived']);
        self::assertNull($repository->location($location['id']));
    }

    public function testConnectedInfrastructureCannotBeArchived(): void
    {
        $this->expectException(ResourceInUseException::class);
        $this->expectExceptionMessage('still in use');

        (new DemoNetworkRepository())->archivePatchPanel(1);
    }

    public function testUpsInventoryPersistsAndProtectsItsServerRoom(): void
    {
        $repository = new DemoNetworkRepository();
        $location = $repository->createLocation(['code' => 'POWER-DC', 'name' => 'Power site', 'address' => '']);
        $room = $repository->createServerRoom($location['id'], ['name' => 'Power room', 'floor' => '0']);
        $upsDevice = $repository->createUpsDevice($room['id'], [
            'name' => 'Main UPS A',
            'manufacturer' => 'APC',
            'model' => 'SRT10KXLI',
            'serial_number' => 'UPS-0001',
            'rated_power_va' => 10000,
            'rated_power_w' => 10000,
            'ip_address' => '192.0.2.10',
            'management_url' => 'https://192.0.2.10',
            'battery_replaced_at' => '2025-05-10',
            'battery_replacement_interval_months' => 36,
            'operational_status' => 'ACTIVE',
            'notes' => 'Protected load group A',
        ]);

        self::assertSame('Main UPS A', $repository->location($location['id'])['rooms_detail'][0]['ups_devices'][0]['name']);
        self::assertSame('2028-05-10', $upsDevice['battery_due_at']);
        $repository->updateUpsDevice($upsDevice['id'], array_replace($upsDevice, ['name' => 'Main UPS A updated', 'operational_status' => 'MAINTENANCE']));
        self::assertSame('maintenance', $repository->location($location['id'])['rooms_detail'][0]['ups_devices'][0]['operational_status']);

        try {
            $repository->archiveServerRoom($room['id']);
            self::fail('A server room containing a UPS device should not be archived');
        } catch (ResourceInUseException $exception) {
            self::assertSame('room_has_ups', $exception->reason);
        }

        self::assertTrue($repository->archiveUpsDevice($upsDevice['id'])['archived']);
        self::assertTrue($repository->archiveServerRoom($room['id'])['archived']);
    }
}
