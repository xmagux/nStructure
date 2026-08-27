<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Exception\ResourceInUseException;
use NStructure\Domain\Repository\NetworkRepository;

final class DemoNetworkRepository implements NetworkRepository
{
    use BuildsInventoryGraph;

    public function dashboard(): array
    {
        $locations = $this->locations();

        return [
            'stats' => [
                ['key' => 'locations', 'value' => count($locations), 'trend' => '+1', 'tone' => 'violet'],
                ['key' => 'active_cables', 'value' => 7, 'trend' => '+2', 'tone' => 'cyan'],
                ['key' => 'fiber_capacity', 'value' => '216J', 'trend' => '87.5%', 'tone' => 'blue'],
                ['key' => 'open_ends', 'value' => 24, 'trend' => '-6', 'tone' => 'amber'],
            ],
            'health' => [
                'score' => 96.4,
                'active' => 189,
                'reserved' => 15,
                'damaged' => 2,
                'available' => 34,
            ],
            'locations' => $locations,
            'cables' => $this->cables(),
            'alerts' => [
                ['severity' => 'warning', 'title' => '12 unterminated fibers', 'detail' => 'Research Campus · PP-LAB-01', 'time' => '18 min'],
                ['severity' => 'danger', 'title' => 'Elevated splice loss', 'detail' => 'Metro Splice M01 · tray 3', 'time' => '2 h'],
                ['severity' => 'info', 'title' => 'Documentation updated', 'detail' => 'CBL-WAW-004 · segment map', 'time' => 'Yesterday'],
            ],
            'activity' => [
                ['initials' => 'MG', 'action' => 'terminated fiber 12 on PP-NORTH-01', 'time' => '8 min ago', 'tone' => 'blue'],
                ['initials' => 'AN', 'action' => 'added cable CBL-WAW-007', 'time' => '42 min ago', 'tone' => 'cyan'],
                ['initials' => 'JS', 'action' => 'updated Metro Splice M01', 'time' => '2 hours ago', 'tone' => 'blue'],
            ],
            'topology' => $this->topology(),
        ];
    }

    public function topology(): array
    {
        $topology = [
            'nodes' => [
                ['id' => 'loc-1', 'entity_id' => 1, 'type' => 'location', 'code' => 'WAW-DC1', 'name' => 'Warsaw Core', 'icon_key' => 'loc-datacenter', 'subtitle' => '2 rooms · 4 racks', 'x' => 15, 'y' => 38, 'status' => 'online'],
                ['id' => 'splice-1', 'entity_id' => 1, 'type' => 'splice', 'code' => 'M01', 'name' => 'Metro Splice', 'subtitle' => '48 active splices', 'x' => 48, 'y' => 40, 'status' => 'warning'],
                ['id' => 'loc-2', 'entity_id' => 2, 'type' => 'location', 'code' => 'WAW-NORTH', 'name' => 'North Office', 'icon_key' => 'loc-office', 'subtitle' => '1 room · 2 racks', 'x' => 80, 'y' => 18, 'status' => 'online'],
                ['id' => 'loc-3', 'entity_id' => 3, 'type' => 'location', 'code' => 'WAW-LAB', 'name' => 'Research Campus', 'icon_key' => 'loc-campus', 'subtitle' => '1 room · 3 racks', 'x' => 80, 'y' => 68, 'status' => 'attention'],
            ],
            'edges' => [
                ['id' => 'seg-1', 'from' => 'loc-1', 'to' => 'splice-1', 'code' => 'SEG-A-M01', 'cable' => 'CBL-WAW-001', 'medium' => 'SM', 'fibers' => 48, 'used' => 48, 'length' => '1.84 km', 'tone' => 'violet'],
                ['id' => 'seg-2', 'from' => 'splice-1', 'to' => 'loc-2', 'code' => 'SEG-M01-B', 'cable' => 'CBL-WAW-001', 'medium' => 'SM', 'fibers' => 24, 'used' => 24, 'length' => '2.26 km', 'tone' => 'cyan'],
                ['id' => 'seg-3', 'from' => 'splice-1', 'to' => 'loc-3', 'code' => 'SEG-M01-C', 'cable' => 'CBL-WAW-001', 'medium' => 'SM', 'fibers' => 24, 'used' => 12, 'length' => '3.12 km', 'tone' => 'amber'],
            ],
            'summary' => ['locations' => 3, 'closures' => 1, 'segments' => 3, 'total_length' => '7.22 km'],
        ];

        foreach ($_SESSION['demo_cables'] ?? [] as $cable) {
            $topology['edges'][] = [
                'id' => 'seg-demo-' . $cable['id'],
                'from' => 'loc-' . $cable['source_location_id'],
                'to' => 'loc-' . $cable['destination_location_id'],
                'code' => $cable['segment_code'],
                'cable' => $cable['code'],
                'medium' => $cable['medium'],
                'fibers' => $cable['fiber_count'],
                'used' => 0,
                'length' => $cable['length'],
                'tone' => $cable['accent'],
            ];
            $topology['summary']['segments']++;
        }

        return $topology;
    }

    public function locations(): array
    {
        $locations = [
            ['id' => 1, 'code' => 'WAW-DC1', 'name' => 'Warsaw Core', 'icon_key' => 'loc-datacenter', 'address' => 'Kasprzaka 18, Warsaw', 'rooms' => 2, 'racks' => 4, 'panels' => 7, 'fibers' => 96, 'utilization' => 91, 'status' => 'healthy', 'accent' => 'violet'],
            ['id' => 2, 'code' => 'WAW-NORTH', 'name' => 'North Office', 'icon_key' => 'loc-office', 'address' => 'Modlinska 61, Warsaw', 'rooms' => 1, 'racks' => 2, 'panels' => 3, 'fibers' => 48, 'utilization' => 84, 'status' => 'healthy', 'accent' => 'cyan'],
            ['id' => 3, 'code' => 'WAW-LAB', 'name' => 'Research Campus', 'icon_key' => 'loc-campus', 'address' => 'Zwirki i Wigury 101, Warsaw', 'rooms' => 1, 'racks' => 3, 'panels' => 4, 'fibers' => 72, 'utilization' => 67, 'status' => 'attention', 'accent' => 'amber'],
        ];

        foreach ($_SESSION['demo_locations'] ?? [] as $location) {
            $locations[] = $location;
        }
        foreach ($locations as &$location) {
            if (isset($_SESSION['demo_location_updates'][$location['id']])) {
                $location = array_replace($location, $_SESSION['demo_location_updates'][$location['id']]);
            }
        }
        unset($location);

        $archived = array_map('intval', $_SESSION['demo_archived_locations'] ?? []);
        return array_values(array_filter(
            $locations,
            static fn (array $location): bool => !in_array((int) $location['id'], $archived, true),
        ));
    }

    public function location(int $id): ?array
    {
        $location = $this->findById($this->locations(), $id);
        if ($location === null) {
            return null;
        }

        $location['rooms_detail'] = match ($id) {
            1 => [
                ['id' => 1, 'code' => 'SR-A', 'name' => 'Core Room A', 'floor' => '-1', 'temperature' => '21.4 °C', 'racks' => [
                    ['id' => 1, 'code' => 'R01', 'name' => 'Core Rack 01', 'units_used' => 31, 'units_total' => 42, 'panels' => 3],
                    ['id' => 4, 'code' => 'R02', 'name' => 'Core Rack 02', 'units_used' => 28, 'units_total' => 42, 'panels' => 2],
                ]],
                ['id' => 4, 'code' => 'SR-A2', 'name' => 'Carrier Room', 'floor' => '-1', 'temperature' => '20.9 °C', 'racks' => [
                    ['id' => 5, 'code' => 'R11', 'name' => 'Carrier Rack 11', 'units_used' => 19, 'units_total' => 42, 'panels' => 2],
                ]],
            ],
            2 => [
                ['id' => 2, 'code' => 'SR-B', 'name' => 'Distribution Room B', 'floor' => '1', 'temperature' => '22.1 °C', 'racks' => [
                    ['id' => 2, 'code' => 'R08', 'name' => 'Distribution Rack 08', 'units_used' => 24, 'units_total' => 42, 'panels' => 2],
                ]],
            ],
            3 => [
                ['id' => 3, 'code' => 'SR-C', 'name' => 'Laboratory Room C', 'floor' => '0', 'temperature' => '21.8 °C', 'racks' => [
                    ['id' => 3, 'code' => 'R03', 'name' => 'Laboratory Rack 03', 'units_used' => 18, 'units_total' => 42, 'panels' => 1],
                ]],
            ],
            default => [],
        };

        foreach ($_SESSION['demo_rooms'][$id] ?? [] as $room) {
            $location['rooms_detail'][] = $room;
        }
        foreach ($location['rooms_detail'] as &$room) {
            if (isset($_SESSION['demo_room_updates'][$room['id']])) {
                $room = array_replace($room, $_SESSION['demo_room_updates'][$room['id']]);
            }
            foreach ($_SESSION['demo_racks'][(int) $room['id']] ?? [] as $rack) {
                $room['racks'][] = $rack;
            }
            foreach ($room['racks'] as &$rack) {
                if (isset($_SESSION['demo_rack_updates'][$rack['id']])) {
                    $rack = array_replace($rack, $_SESSION['demo_rack_updates'][$rack['id']]);
                }
            }
            unset($rack);
            $room['ups_devices'] = [];
            foreach ($_SESSION['demo_ups_devices'][(int) $room['id']] ?? [] as $upsDevice) {
                $upsDeviceId = (int) $upsDevice['id'];
                $room['ups_devices'][] = $this->normalizeDemoUpsDevice(array_replace(
                    $upsDevice,
                    $_SESSION['demo_ups_device_updates'][$upsDeviceId] ?? [],
                ));
            }
            $archivedUpsDevices = array_map('intval', $_SESSION['demo_archived_ups_devices'] ?? []);
            $room['ups_devices'] = array_values(array_filter(
                $room['ups_devices'],
                static fn (array $upsDevice): bool => !in_array((int) $upsDevice['id'], $archivedUpsDevices, true),
            ));
            $room['images'] = $this->assetImages('SERVER_ROOM', (int) $room['id']);
        }
        unset($room);
        $location['images'] = $this->assetImages('LOCATION', $id);

        $archivedRooms = array_map('intval', $_SESSION['demo_archived_rooms'] ?? []);
        $archivedRacks = array_map('intval', $_SESSION['demo_archived_racks'] ?? []);
        $location['rooms_detail'] = array_values(array_filter(
            $location['rooms_detail'],
            static fn (array $room): bool => !in_array((int) $room['id'], $archivedRooms, true),
        ));
        foreach ($location['rooms_detail'] as &$room) {
            $room['racks'] = array_values(array_filter(
                $room['racks'],
                static fn (array $rack): bool => !in_array((int) $rack['id'], $archivedRacks, true),
            ));
        }
        unset($room);

        return $location;
    }

    public function serverRoomExists(int $id): bool
    {
        foreach ($this->locations() as $location) {
            foreach ($this->location((int) $location['id'])['rooms_detail'] ?? [] as $room) {
                if ((int) $room['id'] === $id) {
                    return true;
                }
            }
        }
        return false;
    }

    public function cableExists(int $id): bool
    {
        return $this->findById($this->cables(), $id) !== null;
    }

    public function rack(int $id): ?array
    {
        if (in_array($id, array_map('intval', $_SESSION['demo_archived_racks'] ?? []), true)) {
            return null;
        }
        $racks = [
            1 => [
                'id' => 1, 'server_room_id' => 1, 'code' => 'R01', 'name' => 'Core Rack 01', 'row_label' => 'A', 'room' => 'Core Room A', 'location' => 'Warsaw Core', 'total_units' => 42,
                'power' => '3.4 kW', 'temperature' => '21.4 °C', 'utilization' => 74,
                'devices' => [
                    ['id' => 1, 'type' => 'patch_panel', 'name' => 'Core Fiber Panel', 'code' => 'PP-WAW-01', 'start' => 40, 'height' => 2, 'ports' => 48, 'occupied' => 48, 'tone' => 'violet'],
                    ['id' => 9, 'type' => 'switch', 'name' => 'Core Switch 01', 'code' => 'SW-WAW-01', 'start' => 36, 'height' => 2, 'ports' => 48, 'occupied' => 42, 'tone' => 'cyan'],
                    ['id' => 10, 'type' => 'patch_panel', 'name' => 'Carrier Fiber Panel', 'code' => 'PP-WAW-02', 'start' => 33, 'height' => 1, 'ports' => 24, 'occupied' => 18, 'tone' => 'blue'],
                    ['id' => 11, 'type' => 'organizer', 'name' => 'Horizontal Organizer', 'code' => 'ORG-01', 'start' => 31, 'height' => 1, 'ports' => 0, 'occupied' => 0, 'tone' => 'slate'],
                    ['id' => 12, 'type' => 'device', 'name' => 'Optical Transport', 'code' => 'OT-WAW-01', 'start' => 25, 'height' => 3, 'ports' => 16, 'occupied' => 14, 'tone' => 'amber'],
                ],
            ],
            2 => ['id' => 2, 'server_room_id' => 2, 'code' => 'R08', 'name' => 'Distribution Rack 08', 'row_label' => 'B', 'room' => 'Distribution Room B', 'location' => 'North Office', 'total_units' => 42, 'power' => '2.1 kW', 'temperature' => '22.1 °C', 'utilization' => 57, 'devices' => [
                ['id' => 2, 'type' => 'patch_panel', 'name' => 'North Distribution Panel', 'code' => 'PP-NORTH-01', 'start' => 39, 'height' => 1, 'ports' => 24, 'occupied' => 24, 'tone' => 'cyan'],
            ]],
            3 => ['id' => 3, 'server_room_id' => 3, 'code' => 'R03', 'name' => 'Laboratory Rack 03', 'row_label' => 'A', 'room' => 'Laboratory Room C', 'location' => 'Research Campus', 'total_units' => 42, 'power' => '1.8 kW', 'temperature' => '21.8 °C', 'utilization' => 43, 'devices' => [
                ['id' => 3, 'type' => 'patch_panel', 'name' => 'Research Laboratory Panel', 'code' => 'PP-LAB-01', 'start' => 38, 'height' => 1, 'ports' => 24, 'occupied' => 12, 'tone' => 'amber'],
            ]],
        ];

        if (isset($racks[$id])) {
            $rack = array_replace($racks[$id], $_SESSION['demo_rack_updates'][$id] ?? []);
            $rack['location_id'] = (int) ($rack['location_id'] ?? match ((string) $rack['location']) {
                'Warsaw Core' => 1,
                'North Office' => 2,
                'Research Campus' => 3,
                default => 0,
            });
            foreach ($_SESSION['demo_panels'][$id] ?? [] as $panel) {
                $rack['devices'][] = [
                    'id' => $panel['id'],
                    'type' => 'patch_panel',
                    'name' => $panel['name'],
                    'code' => $panel['code'],
                    'start' => $panel['rack_unit_start'],
                    'height' => $panel['rack_unit_height'],
                    'ports' => $panel['ports'],
                    'occupied' => $panel['occupied'],
                    'tone' => 'violet',
                ];
            }
            $archivedPanels = array_map('intval', $_SESSION['demo_archived_panels'] ?? []);
            $rack['devices'] = array_values(array_filter(
                $rack['devices'],
                static fn (array $device): bool => $device['type'] !== 'patch_panel'
                    || !in_array((int) $device['id'], $archivedPanels, true),
            ));
            $rack['devices'] = array_map(function (array $device): array {
                if ($device['type'] !== 'patch_panel') {
                    return $device;
                }
                $panel = $this->panel((int) $device['id']);
                if ($panel === null) {
                    return $device;
                }
                $device['rows'] = (int) $panel['layout_rows'];
                $device['port_items'] = array_map(static fn (array $port): array => [
                    'number' => (int) $port['number'],
                    'status' => $port['status'],
                    'label' => $port['label'],
                    'highlight_color' => $port['highlight_color'] ?? null,
                    'destination' => $port['destination'],
                    'rear_destination' => $port['rear_destination'] ?? $port['destination'],
                    'front_destination' => $port['front_destination'] ?? null,
                ], $panel['port_items']);
                return $device;
            }, $rack['devices']);
            $rack['devices'] = [...$rack['devices'], ...$this->rackItemsForRack($id)];
            $rack['active_devices'] = $this->activeDevicesForRack($id);
            $rack['images'] = $this->assetImages('RACK', $id);
            return $rack;
        }
        foreach ($_SESSION['demo_racks'] ?? [] as $roomRacks) {
            foreach ($roomRacks as $rack) {
                if ((int) $rack['id'] === $id) {
                    $storedRack = array_replace($rack + ['devices' => [], 'row_label' => null, 'server_room_id' => 0], $_SESSION['demo_rack_updates'][$id] ?? []);
                    $storedRack['devices'] = [...$storedRack['devices'], ...$this->rackItemsForRack($id)];
                    $storedRack['active_devices'] = $this->activeDevicesForRack($id);
                    $storedRack['images'] = $this->assetImages('RACK', $id);
                    return $storedRack;
                }
            }
        }
        return null;
    }

    public function panel(int $id): ?array
    {
        if (in_array($id, array_map('intval', $_SESSION['demo_archived_panels'] ?? []), true)) {
            return null;
        }
        $definitions = [
            1 => ['rack_id' => 1, 'fiber_node_id' => 1, 'code' => 'PP-WAW-01', 'name' => 'Core Fiber Panel', 'rack' => 'R01', 'room' => 'Core Room A', 'location' => 'Warsaw Core', 'rack_unit_start' => 40, 'rack_unit_height' => 2, 'ports' => 48, 'occupied' => 48, 'terminated_ports' => 48, 'incoming_capacity' => 48, 'layout_rows' => 2, 'layout_columns' => 24, 'connector' => 'LC', 'connector_type_id' => 4, 'manufacturer' => 'Fibrain', 'model' => 'FDP-48-LC', 'incoming' => 'CBL-WAW-001 · 48J SM'],
            2 => ['rack_id' => 2, 'fiber_node_id' => 2, 'code' => 'PP-NORTH-01', 'name' => 'North Distribution Panel', 'rack' => 'R08', 'room' => 'Distribution Room B', 'location' => 'North Office', 'rack_unit_start' => 39, 'rack_unit_height' => 1, 'ports' => 24, 'occupied' => 24, 'terminated_ports' => 24, 'incoming_capacity' => 24, 'layout_rows' => 1, 'layout_columns' => 24, 'connector' => 'SC-APC', 'connector_type_id' => 3, 'manufacturer' => 'Fibrain', 'model' => 'FDP-24-SC', 'incoming' => 'CBL-WAW-001 · 24J SM'],
            3 => ['rack_id' => 3, 'fiber_node_id' => 3, 'code' => 'PP-LAB-01', 'name' => 'Research Laboratory Panel', 'rack' => 'R03', 'room' => 'Laboratory Room C', 'location' => 'Research Campus', 'rack_unit_start' => 38, 'rack_unit_height' => 1, 'ports' => 24, 'occupied' => 12, 'terminated_ports' => 12, 'incoming_capacity' => 24, 'layout_rows' => 1, 'layout_columns' => 24, 'connector' => 'E2000', 'connector_type_id' => 1, 'manufacturer' => 'Fibrain', 'model' => 'FDP-24-SC', 'incoming' => 'CBL-WAW-001 · 24J SM'],
            10 => ['rack_id' => 1, 'fiber_node_id' => 10, 'code' => 'PP-WAW-02', 'name' => 'Carrier Fiber Panel', 'rack' => 'R01', 'room' => 'Core Room A', 'location' => 'Warsaw Core', 'rack_unit_start' => 33, 'rack_unit_height' => 1, 'ports' => 24, 'occupied' => 18, 'terminated_ports' => 18, 'incoming_capacity' => 24, 'layout_rows' => 1, 'layout_columns' => 24, 'connector' => 'LC', 'connector_type_id' => 4, 'manufacturer' => 'Fibrain', 'model' => 'FDP-24-LC', 'incoming' => 'CBL-WAW-002 · 24J SM'],
        ];

        if (!isset($definitions[$id])) {
            foreach ($_SESSION['demo_panels'] ?? [] as $rackPanels) {
                foreach ($rackPanels as $storedPanel) {
                    if ((int) $storedPanel['id'] === $id) {
                        $storedPanel['images'] = $this->assetImages('PATCH_PANEL', $id);
                        return $storedPanel;
                    }
                }
            }
            return null;
        }

        $panel = array_replace(['id' => $id] + $definitions[$id], $_SESSION['demo_panel_updates'][$id] ?? []);
        $panel['location_id'] = (int) ($panel['location_id'] ?? match ((string) $panel['location']) {
            'Warsaw Core' => 1,
            'North Office' => 2,
            'Research Campus' => 3,
            default => 0,
        });
        $panel['available'] = $panel['ports'] - $panel['occupied'];
        $panel['utilization'] = round($panel['occupied'] * 100 / $panel['ports']);
        $panel['unterminated'] = match ($id) {
            3 => 12,
            10 => 6,
            default => 0,
        };
        $panel['port_items'] = [];

        for ($number = 1; $number <= $panel['ports']; $number++) {
            $occupied = $number <= $panel['occupied'];
            $portId = (($id - 1) * 48) + $number;
            $portUpdate = $_SESSION['demo_port_updates'][$portId] ?? [];
            $frontConnection = $portUpdate['front_connection'] ?? null;
            $rearDestination = $occupied ? $this->portDestination($id, $number) : ($portUpdate['remote_endpoint_label'] ?? null);
            $panel['port_items'][] = [
                'id' => $portId,
                'number' => $number,
                'status' => $occupied || $frontConnection ? 'occupied' : 'available',
                'administrative_status' => $portUpdate['administrative_status'] ?? 'AVAILABLE',
                'connector_type_id' => $portUpdate['connector_type_id'] ?? $panel['connector_type_id'],
                'connector' => $panel['connector'],
                'label' => $portUpdate['label'] ?? null,
                'highlight_color' => $portUpdate['highlight_color'] ?? null,
                'manual_remote_endpoint' => $portUpdate['remote_endpoint_label'] ?? null,
                'notes' => $portUpdate['notes'] ?? null,
                'fiber' => sprintf('%s / %02d', match ($id) {
                    1 => 'SEG-A-M01',
                    2 => 'SEG-M01-B',
                    10 => 'SEG-A-M02',
                    default => 'SEG-M01-C',
                }, $number),
                'destination' => $rearDestination,
                'rear_destination' => $rearDestination,
                'front_destination' => $frontConnection['label'] ?? null,
                'front_connection' => $frontConnection,
                'has_termination' => $occupied,
                'has_patch_cord' => false,
                'has_front_connection' => $frontConnection !== null,
                'loss' => $occupied ? number_format(0.14 + (($number % 5) * 0.03), 2) . ' dB' : null,
                'color' => $this->fiberColor($number),
            ];
        }

        $panel['images'] = $this->assetImages('PATCH_PANEL', $id);
        return $panel;
    }

    public function assetImage(int $id): ?array
    {
        $image = $_SESSION['demo_asset_images'][$id] ?? null;
        return is_array($image) ? $this->normalizeAssetImage($image) : null;
    }

    public function addAssetImage(string $entityType, int $entityId, array $metadata): array
    {
        if (!in_array($entityType, ['RACK', 'PATCH_PANEL', 'LOCATION', 'SERVER_ROOM', 'CABLE'], true)) {
            throw new \RuntimeException('Unsupported image entity type');
        }
        $id = (int) ($_SESSION['demo_asset_image_sequence'] ?? 1000) + 1;
        $_SESSION['demo_asset_image_sequence'] = $id;
        $image = [
            'id' => $id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'storage_path' => $metadata['storage_path'],
            'original_name' => $metadata['original_name'],
            'mime_type' => $metadata['mime_type'],
            'size_bytes' => $metadata['size_bytes'],
            'width_px' => $metadata['width_px'],
            'height_px' => $metadata['height_px'],
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        $_SESSION['demo_asset_images'][$id] = $image;
        return $this->normalizeAssetImage($image);
    }

    public function cables(): array
    {
        $cables = [
            ['id' => 1, 'code' => 'CBL-WAW-001', 'name' => 'Warsaw Metro Branch', 'medium' => 'SM', 'fiber_count' => 48, 'used' => 42, 'status' => 'active', 'operational_status' => 'ACTIVE', 'source_location_id' => 1, 'destination_location_id' => 2, 'length_m' => 7220, 'source' => 'Warsaw Core', 'destinations' => ['North Office', 'Research Campus'], 'length' => '7.22 km', 'segments' => 3, 'updated' => '8 min ago', 'accent' => 'violet'],
            ['id' => 2, 'code' => 'CBL-WAW-002', 'name' => 'Core Redundant West', 'medium' => 'SM', 'fiber_count' => 48, 'used' => 38, 'status' => 'active', 'operational_status' => 'ACTIVE', 'source_location_id' => 1, 'destination_location_id' => 2, 'length_m' => 5810, 'source' => 'Warsaw Core', 'destinations' => ['North Office'], 'length' => '5.81 km', 'segments' => 2, 'updated' => '3 days ago', 'accent' => 'cyan'],
            ['id' => 3, 'code' => 'CBL-LAB-004', 'name' => 'Campus Multimode Link', 'medium' => 'MM', 'fiber_count' => 24, 'used' => 18, 'status' => 'maintenance', 'operational_status' => 'MAINTENANCE', 'source_location_id' => 3, 'destination_location_id' => 2, 'length_m' => 420, 'source' => 'Research Campus', 'destinations' => ['Laboratory Annex'], 'length' => '0.42 km', 'segments' => 1, 'updated' => 'Yesterday', 'accent' => 'amber'],
        ];
        foreach ($_SESSION['demo_cables'] ?? [] as $cable) {
            $cables[] = $cable;
        }
        foreach ($cables as &$cable) {
            if (isset($_SESSION['demo_cable_updates'][$cable['id']])) {
                $cable = array_replace($cable, $_SESSION['demo_cable_updates'][$cable['id']]);
            }
            $cable['source_endpoint_key'] ??= 'LOCATION:' . (int) ($cable['source_location_id'] ?? 0);
            $cable['destination_endpoint_key'] ??= 'LOCATION:' . (int) ($cable['destination_location_id'] ?? 0);
            $cable['images'] = $this->assetImages('CABLE', (int) $cable['id']);
        }
        unset($cable);
        $archived = array_map('intval', $_SESSION['demo_archived_cables'] ?? []);
        return array_values(array_filter(
            $cables,
            static fn (array $cable): bool => !in_array((int) $cable['id'], $archived, true),
        ));
    }

    public function cableEndpointOptions(): array
    {
        $options = ['locations' => [], 'rooms' => [], 'racks' => []];
        foreach ($this->locations() as $location) {
            $locationId = (int) $location['id'];
            $options['locations'][] = [
                'key' => 'LOCATION:' . $locationId,
                'id' => $locationId,
                'location_id' => $locationId,
                'server_room_id' => null,
                'rack_id' => null,
                'location_code' => $location['code'],
                'location_name' => $location['name'],
                'label' => $location['code'] . ' · ' . $location['name'],
            ];
            $detail = $this->location($locationId);
            foreach ($detail['rooms_detail'] ?? [] as $room) {
                $roomId = (int) $room['id'];
                $options['rooms'][] = [
                    'key' => 'ROOM:' . $roomId,
                    'id' => $roomId,
                    'location_id' => $locationId,
                    'server_room_id' => $roomId,
                    'rack_id' => null,
                    'location_code' => $location['code'],
                    'location_name' => $location['name'],
                    'label' => $location['code'] . ' · ' . $room['name'],
                ];
                foreach ($room['racks'] ?? [] as $rack) {
                    $rackId = (int) $rack['id'];
                    $options['racks'][] = [
                        'key' => 'RACK:' . $rackId,
                        'id' => $rackId,
                        'location_id' => $locationId,
                        'server_room_id' => $roomId,
                        'rack_id' => $rackId,
                        'location_code' => $location['code'],
                        'location_name' => $location['name'],
                        'label' => $location['code'] . ' · ' . $room['name'] . ' · ' . $rack['code'],
                    ];
                }
            }
        }
        return $options;
    }

    public function search(string $query): array
    {
        $query = mb_strtolower(trim($query));
        if (mb_strlen($query) < 2) {
            return [];
        }
        $results = [];
        foreach ($this->locations() as $location) {
            $this->appendSearchResult($results, $query, 'location', (int) $location['id'], $location['code'], $location['name'], (string) ($location['address'] ?? ''), '/locations/' . $location['id']);
            $detail = $this->location((int) $location['id']);
            foreach ($detail['rooms_detail'] ?? [] as $room) {
                $this->appendSearchResult($results, $query, 'room', (int) $room['id'], $room['code'], $room['name'], $location['name'], '/locations/' . $location['id']);
                foreach ($room['racks'] ?? [] as $rack) {
                    $this->appendSearchResult($results, $query, 'rack', (int) $rack['id'], $rack['code'], $rack['name'], $location['name'] . ' · ' . $room['name'], '/racks/' . $rack['id']);
                }
            }
        }
        foreach ([1, 2, 3, 10] as $panelId) {
            $panel = $this->panel($panelId);
            if ($panel !== null) {
                $this->appendSearchResult($results, $query, 'panel', $panelId, $panel['code'], $panel['name'], $panel['location'] . ' · ' . $panel['rack'], '/patch-panels/' . $panelId);
            }
        }
        foreach ($_SESSION['demo_panels'] ?? [] as $rackPanels) {
            foreach ($rackPanels as $panel) {
                $this->appendSearchResult($results, $query, 'panel', (int) $panel['id'], $panel['code'], $panel['name'], $panel['location'] . ' · ' . $panel['rack'], '/patch-panels/' . $panel['id']);
            }
        }
        foreach ($this->cables() as $cable) {
            $this->appendSearchResult($results, $query, 'cable', (int) $cable['id'], $cable['code'], $cable['name'], $cable['fiber_count'] . 'J · ' . $cable['medium'], '/cables');
        }

        return array_slice($results, 0, 24);
    }

    public function connectorTypes(): array
    {
        return [
            ['id' => 4, 'code' => 'LC'],
            ['id' => 8, 'code' => 'LC-UPC'],
            ['id' => 9, 'code' => 'LC-APC'],
            ['id' => 10, 'code' => 'SC'],
            ['id' => 11, 'code' => 'SC-UPC'],
            ['id' => 3, 'code' => 'SC-APC'],
            ['id' => 2, 'code' => 'SC-PC'],
            ['id' => 1, 'code' => 'E2000'],
            ['id' => 12, 'code' => 'E2000-UPC'],
            ['id' => 13, 'code' => 'E2000-APC'],
            ['id' => 5, 'code' => 'FC'],
            ['id' => 6, 'code' => 'ST'],
            ['id' => 7, 'code' => 'MPO'],
            ['id' => 14, 'code' => 'INNE'],
        ];
    }

    public function activeDeviceOptions(): array
    {
        $racks = [
            ['id' => 1, 'code' => 'R01', 'name' => 'Core Rack 01', 'room' => 'Core Room A', 'location' => 'Warsaw Core'],
            ['id' => 2, 'code' => 'R08', 'name' => 'Distribution Rack 08', 'room' => 'Distribution Room B', 'location' => 'North Office'],
            ['id' => 3, 'code' => 'R03', 'name' => 'Laboratory Rack 03', 'room' => 'Laboratory Room C', 'location' => 'Research Campus'],
        ];
        foreach ($_SESSION['demo_racks'] ?? [] as $roomRacks) {
            foreach ($roomRacks as $rack) {
                $racks[] = [
                    'id' => (int) $rack['id'],
                    'code' => $rack['code'],
                    'name' => $rack['name'],
                    'room' => $rack['room'],
                    'location' => $rack['location'],
                ];
            }
        }
        foreach ($racks as &$rack) {
            $rack['label'] = implode(' · ', [$rack['location'], $rack['room'], $rack['code'], $rack['name']]);
        }
        unset($rack);

        $devices = [
            ['id' => 701, 'rack_id' => 1, 'code' => 'SW-WAW-01', 'name' => 'Core Switch 01', 'device_type' => 'SWITCH', 'vendor' => 'Juniper', 'model' => 'EX4650', 'rack' => 'R01', 'room' => 'Core Room A', 'location' => 'Warsaw Core'],
            ['id' => 702, 'rack_id' => 1, 'code' => 'FW-WAW-01', 'name' => 'Edge Firewall 01', 'device_type' => 'FIREWALL', 'vendor' => 'Palo Alto Networks', 'model' => 'PA-3410', 'rack' => 'R01', 'room' => 'Core Room A', 'location' => 'Warsaw Core'],
            ['id' => 703, 'rack_id' => 2, 'code' => 'FW-NORTH-01', 'name' => 'Branch Firewall 01', 'device_type' => 'FIREWALL', 'vendor' => 'Fortinet', 'model' => 'FortiGate 200F', 'rack' => 'R08', 'room' => 'Distribution Room B', 'location' => 'North Office'],
        ];
        foreach ($_SESSION['demo_active_devices'] ?? [] as $device) {
            $devices[] = $device;
        }
        $archivedDevices = array_map('intval', $_SESSION['demo_archived_active_devices'] ?? []);
        $devices = array_values(array_filter(
            array_map(fn (array $device): array => array_replace($device, $_SESSION['demo_active_device_updates'][$device['id']] ?? []), $devices),
            static fn (array $device): bool => !in_array((int) $device['id'], $archivedDevices, true),
        ));
        foreach ($devices as &$device) {
            $device['label'] = implode(' · ', array_filter([$device['location'], $device['rack'], $device['vendor'], $device['name'], $device['model']]));
        }
        unset($device);

        return ['racks' => $racks, 'devices' => $devices];
    }

    public function createLocation(array $input): array
    {
        $nextId = 100 + count($_SESSION['demo_locations'] ?? []);
        $location = [
            'id' => $nextId,
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'icon_key' => trim((string) ($input['icon_key'] ?? '')) ?: 'loc-office',
            'address' => trim((string) ($input['address'] ?? '')),
            'rooms' => 0,
            'racks' => 0,
            'panels' => 0,
            'fibers' => 0,
            'utilization' => 0,
            'status' => 'healthy',
            'accent' => 'blue',
        ];

        $_SESSION['demo_locations'][] = $location;

        return $location;
    }

    public function updateLocation(int $locationId, array $input): array
    {
        if ($this->findById($this->locations(), $locationId) === null) {
            throw new \RuntimeException('Location not found');
        }
        $record = ['id' => $locationId, 'code' => strtoupper(trim((string) $input['code'])), 'name' => trim((string) $input['name']), 'icon_key' => trim((string) ($input['icon_key'] ?? '')) ?: 'loc-office', 'address' => trim((string) ($input['address'] ?? ''))];
        $_SESSION['demo_location_updates'][$locationId] = $record;
        return $record;
    }

    public function archiveLocation(int $locationId): array
    {
        $location = $this->location($locationId);
        if ($location === null) {
            throw new \RuntimeException('Location not found');
        }
        if (($location['rooms_detail'] ?? []) !== []) {
            throw new ResourceInUseException('location_has_rooms');
        }
        if ($this->demoCableUsesEndpoint('LOCATION:' . $locationId)) {
            throw new ResourceInUseException('location_used_by_cable');
        }
        $_SESSION['demo_archived_locations'][] = $locationId;
        return ['id' => $locationId, 'code' => $location['code'], 'archived' => true];
    }

    public function createServerRoom(int $locationId, array $input): array
    {
        if ($this->findById($this->locations(), $locationId) === null) {
            throw new \RuntimeException('Location not found');
        }
        $room = [
            'id' => 200 + array_sum(array_map('count', $_SESSION['demo_rooms'] ?? [])),
            'code' => sprintf('SR-%03d', 1 + array_sum(array_map('count', $_SESSION['demo_rooms'] ?? []))),
            'name' => trim((string) $input['name']),
            'floor' => trim((string) ($input['floor'] ?? '')),
            'temperature' => '—',
            'racks' => [],
        ];
        $_SESSION['demo_rooms'][$locationId][] = $room;
        return $room;
    }

    public function updateServerRoom(int $serverRoomId, array $input): array
    {
        $record = ['id' => $serverRoomId, 'location_id' => (int) $input['location_id'], 'name' => trim((string) $input['name']), 'floor' => trim((string) ($input['floor'] ?? ''))];
        $_SESSION['demo_room_updates'][$serverRoomId] = $record;
        return $record;
    }

    public function archiveServerRoom(int $serverRoomId): array
    {
        foreach ($this->locations() as $location) {
            $detail = $this->location((int) $location['id']);
            foreach ($detail['rooms_detail'] ?? [] as $room) {
                if ((int) $room['id'] !== $serverRoomId) {
                    continue;
                }
                if (($room['racks'] ?? []) !== []) {
                    throw new ResourceInUseException('room_has_racks');
                }
                if (($room['ups_devices'] ?? []) !== []) {
                    throw new ResourceInUseException('room_has_ups');
                }
                if ($this->demoCableUsesEndpoint('ROOM:' . $serverRoomId)) {
                    throw new ResourceInUseException('room_used_by_cable');
                }
                $_SESSION['demo_archived_rooms'][] = $serverRoomId;
                return ['id' => $serverRoomId, 'code' => $room['code'], 'archived' => true];
            }
        }
        throw new \RuntimeException('Server room not found');
    }

    public function createUpsDevice(int $serverRoomId, array $input): array
    {
        if ($this->demoServerRoomContext($serverRoomId) === null) {
            throw new \RuntimeException('Server room not found');
        }
        $upsDeviceId = 700 + array_sum(array_map('count', $_SESSION['demo_ups_devices'] ?? []));
        $upsDevice = [
            'id' => $upsDeviceId,
            'server_room_id' => $serverRoomId,
            'code' => sprintf('UPS-SR%d-%04d', $serverRoomId, $upsDeviceId),
            'name' => trim((string) $input['name']),
            'manufacturer' => trim((string) ($input['manufacturer'] ?? '')) ?: null,
            'model' => trim((string) ($input['model'] ?? '')) ?: null,
            'serial_number' => trim((string) ($input['serial_number'] ?? '')) ?: null,
            'rated_power_va' => ($input['rated_power_va'] ?? '') === '' ? null : (int) $input['rated_power_va'],
            'rated_power_w' => ($input['rated_power_w'] ?? '') === '' ? null : (int) $input['rated_power_w'],
            'ip_address' => trim((string) ($input['ip_address'] ?? '')) ?: null,
            'management_url' => trim((string) ($input['management_url'] ?? '')) ?: null,
            'battery_replaced_at' => trim((string) ($input['battery_replaced_at'] ?? '')) ?: null,
            'battery_replacement_interval_months' => (int) ($input['battery_replacement_interval_months'] ?? 36),
            'battery_count' => ($input['battery_count'] ?? '') === '' ? null : (int) $input['battery_count'],
            'battery_type' => trim((string) ($input['battery_type'] ?? '')) ?: null,
            'operational_status' => strtolower(trim((string) ($input['operational_status'] ?? 'ACTIVE'))),
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
        $_SESSION['demo_ups_devices'][$serverRoomId][] = $upsDevice;
        return $this->normalizeDemoUpsDevice($upsDevice);
    }

    public function updateUpsDevice(int $upsDeviceId, array $input): array
    {
        foreach ($_SESSION['demo_ups_devices'] ?? [] as $upsDevices) {
            foreach ($upsDevices as $upsDevice) {
                if ((int) $upsDevice['id'] !== $upsDeviceId) {
                    continue;
                }
                $update = $this->createUpsDeviceRecord($upsDeviceId, (int) $upsDevice['server_room_id'], (string) $upsDevice['code'], $input);
                $_SESSION['demo_ups_device_updates'][$upsDeviceId] = $update;
                return $this->normalizeDemoUpsDevice($update);
            }
        }
        throw new \RuntimeException('UPS device not found');
    }

    public function archiveUpsDevice(int $upsDeviceId): array
    {
        foreach ($_SESSION['demo_ups_devices'] ?? [] as $upsDevices) {
            foreach ($upsDevices as $upsDevice) {
                if ((int) $upsDevice['id'] === $upsDeviceId) {
                    $_SESSION['demo_archived_ups_devices'][] = $upsDeviceId;
                    return ['id' => $upsDeviceId, 'code' => $upsDevice['code'], 'archived' => true];
                }
            }
        }
        throw new \RuntimeException('UPS device not found');
    }

    public function createRack(int $serverRoomId, array $input): array
    {
        $roomContext = $this->demoServerRoomContext($serverRoomId);
        if ($roomContext === null) {
            throw new \RuntimeException('Server room not found');
        }
        $rack = [
            'id' => 300 + array_sum(array_map('count', $_SESSION['demo_racks'] ?? [])),
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'units_used' => 0,
            'units_total' => (int) $input['total_units'],
            'panels' => 0,
            'room' => $roomContext['room_name'],
            'location' => $roomContext['location_name'],
            'total_units' => (int) $input['total_units'],
            'row_label' => trim((string) ($input['row_label'] ?? '')),
            'server_room_id' => $serverRoomId,
            'location_id' => $roomContext['location_id'],
            'power' => '—',
            'temperature' => '—',
            'utilization' => 0,
        ];
        $_SESSION['demo_racks'][$serverRoomId][] = $rack;
        return $rack;
    }

    public function updateRack(int $rackId, array $input): array
    {
        if ($this->rack($rackId) === null) {
            throw new \RuntimeException('Rack not found');
        }
        $record = ['id' => $rackId, 'code' => strtoupper(trim((string) $input['code'])), 'name' => trim((string) $input['name']), 'total_units' => (int) $input['total_units'], 'units_total' => (int) $input['total_units'], 'row_label' => trim((string) ($input['row_label'] ?? ''))];
        $_SESSION['demo_rack_updates'][$rackId] = $record;
        return $record;
    }

    public function archiveRack(int $rackId): array
    {
        $rack = $this->rack($rackId);
        if ($rack === null) {
            throw new \RuntimeException('Rack not found');
        }
        if (array_filter($rack['devices'] ?? [], static fn (array $device): bool => $device['type'] === 'patch_panel') !== []) {
            throw new ResourceInUseException('rack_has_panels');
        }
        if (array_filter($rack['devices'] ?? [], static fn (array $device): bool => $device['type'] !== 'patch_panel') !== []) {
            throw new ResourceInUseException('rack_has_devices');
        }
        if ($this->activeDevicesForRack($rackId) !== []) {
            throw new ResourceInUseException('rack_has_devices');
        }
        if ($this->demoCableUsesEndpoint('RACK:' . $rackId)) {
            throw new ResourceInUseException('rack_used_by_cable');
        }
        $_SESSION['demo_archived_racks'][] = $rackId;
        return ['id' => $rackId, 'code' => $rack['code'], 'archived' => true];
    }

    public function createPatchPanel(int $rackId, array $input): array
    {
        $rack = $this->rack($rackId);
        if ($rack === null) {
            throw new \RuntimeException('Rack not found');
        }
        $start = (int) $input['rack_unit_start'];
        $height = (int) $input['rack_unit_height'];
        foreach ($rack['devices'] as $device) {
            $deviceStart = (int) $device['start'];
            $deviceLowest = $deviceStart - (int) $device['height'] + 1;
            if ($start - $height + 1 <= $deviceStart && $start >= $deviceLowest) {
                throw new \RuntimeException('Selected rack units are already occupied');
            }
        }
        $id = 500 + array_sum(array_map('count', $_SESSION['demo_panels'] ?? []));
        $connectorCode = array_column($this->connectorTypes(), 'code', 'id')[(int) $input['connector_type_id']] ?? 'LC';
        $panel = [
            'id' => $id,
            'rack_id' => $rackId,
            'code' => sprintf('PP-R%d-%03d', $rackId, count($_SESSION['demo_panels'][$rackId] ?? []) + 1),
            'name' => trim((string) $input['name']),
            'rack' => $rack['code'],
            'room' => $rack['room'],
            'location' => $rack['location'],
            'location_id' => (int) ($rack['location_id'] ?? 0),
            'rack_unit_start' => $start,
            'rack_unit_height' => $height,
            'ports' => (int) $input['port_count'],
            'occupied' => 0,
            'available' => (int) $input['port_count'],
            'utilization' => 0,
            'unterminated' => 0,
            'terminated_ports' => 0,
            'incoming_capacity' => 0,
            'layout_rows' => (int) $input['layout_rows'],
            'layout_columns' => (int) $input['layout_columns'],
            'connector' => $connectorCode,
            'connector_type_id' => (int) $input['connector_type_id'],
            'manufacturer' => trim((string) ($input['manufacturer'] ?? '')),
            'model' => trim((string) ($input['model'] ?? '')),
            'incoming' => '—',
            'port_items' => [],
        ];
        for ($number = 1; $number <= $panel['ports']; $number++) {
            $panel['port_items'][] = [
                'id' => ($id * 1000) + $number,
                'number' => $number,
                'status' => 'available',
                'administrative_status' => 'AVAILABLE',
                'connector_type_id' => (int) $input['connector_type_id'],
                'connector' => $connectorCode,
                'label' => null,
                'highlight_color' => null,
                'manual_remote_endpoint' => null,
                'notes' => null,
                'fiber' => null,
                'destination' => null,
                'rear_destination' => null,
                'front_destination' => null,
                'front_connection' => null,
                'connection_code' => null,
                'has_termination' => false,
                'has_patch_cord' => false,
                'has_front_connection' => false,
                'loss' => null,
                'color' => 'slate',
            ];
        }
        $_SESSION['demo_panels'][$rackId][] = $panel;
        return ['id' => $id, 'rack_id' => $rackId, 'code' => $panel['code'], 'name' => $panel['name']];
    }

    public function updatePatchPanel(int $panelId, array $input): array
    {
        $panel = $this->panel($panelId);
        if ($panel === null) {
            throw new \RuntimeException('Patch panel not found');
        }
        $record = [
            'id' => $panelId,
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'rack_unit_start' => (int) $input['rack_unit_start'],
            'rack_unit_height' => (int) $input['rack_unit_height'],
            'ports' => (int) $input['port_count'],
            'layout_rows' => (int) $input['layout_rows'],
            'layout_columns' => (int) $input['layout_columns'],
            'connector_type_id' => (int) $input['connector_type_id'],
            'connector' => array_column($this->connectorTypes(), 'code', 'id')[(int) $input['connector_type_id']] ?? 'LC',
            'manufacturer' => trim((string) ($input['manufacturer'] ?? '')),
            'model' => trim((string) ($input['model'] ?? '')),
        ];
        foreach ($_SESSION['demo_panels'] ?? [] as $rackId => &$rackPanels) {
            foreach ($rackPanels as &$storedPanel) {
                if ((int) $storedPanel['id'] !== $panelId) {
                    continue;
                }
                $currentCount = count($storedPanel['port_items']);
                if ($record['ports'] > $currentCount) {
                    for ($number = $currentCount + 1; $number <= $record['ports']; $number++) {
                        $storedPanel['port_items'][] = ['id' => ($panelId * 1000) + $number, 'number' => $number, 'status' => 'available', 'administrative_status' => 'AVAILABLE', 'connector_type_id' => $record['connector_type_id'], 'connector' => $record['connector'], 'label' => null, 'manual_remote_endpoint' => null, 'notes' => null, 'fiber' => null, 'destination' => null, 'rear_destination' => null, 'front_destination' => null, 'front_connection' => null, 'connection_code' => null, 'has_termination' => false, 'has_patch_cord' => false, 'has_front_connection' => false, 'loss' => null, 'color' => 'slate'];
                    }
                } else {
                    $storedPanel['port_items'] = array_slice($storedPanel['port_items'], 0, $record['ports']);
                }
                foreach ($storedPanel['port_items'] as &$port) {
                    $port['connector_type_id'] = $record['connector_type_id'];
                    $port['connector'] = $record['connector'];
                }
                unset($port);
                $storedPanel = array_replace($storedPanel, $record);
                $storedPanel['available'] = max(0, $record['ports'] - (int) $storedPanel['occupied']);
                break 2;
            }
            unset($storedPanel);
        }
        unset($rackPanels);
        $_SESSION['demo_panel_updates'][$panelId] = $record;
        return ['id' => $panelId, 'code' => $record['code'], 'name' => $record['name']];
    }

    public function archivePatchPanel(int $panelId): array
    {
        $panel = $this->panel($panelId);
        if ($panel === null) {
            throw new \RuntimeException('Patch panel not found');
        }
        foreach ($panel['port_items'] ?? [] as $port) {
            if (
                strtoupper((string) ($port['administrative_status'] ?? 'AVAILABLE')) !== 'AVAILABLE'
                || (bool) ($port['has_termination'] ?? false)
                || (bool) ($port['has_patch_cord'] ?? false)
                || (bool) ($port['has_front_connection'] ?? false)
            ) {
                throw new ResourceInUseException('panel_has_used_ports');
            }
        }
        $_SESSION['demo_archived_panels'][] = $panelId;
        return ['id' => $panelId, 'code' => $panel['code'], 'archived' => true];
    }

    private function demoActiveDeviceFixtures(): array
    {
        return [
            701 => ['id' => 701, 'rack_id' => 1, 'code' => 'SW-WAW-01', 'name' => 'Core Switch 01', 'device_type' => 'SWITCH', 'vendor' => 'Juniper', 'model' => 'EX4650', 'management_address' => null, 'notes' => null],
            702 => ['id' => 702, 'rack_id' => 1, 'code' => 'FW-WAW-01', 'name' => 'Edge Firewall 01', 'device_type' => 'FIREWALL', 'vendor' => 'Palo Alto Networks', 'model' => 'PA-3410', 'management_address' => null, 'notes' => null],
            703 => ['id' => 703, 'rack_id' => 2, 'code' => 'FW-NORTH-01', 'name' => 'Branch Firewall 01', 'device_type' => 'FIREWALL', 'vendor' => 'Fortinet', 'model' => 'FortiGate 200F', 'management_address' => null, 'notes' => null],
        ];
    }

    private function demoActiveDeviceConnectionCount(int $deviceId): int
    {
        $panelIds = [1, 2, 3, 10];
        foreach ($_SESSION['demo_panels'] ?? [] as $rackPanels) {
            foreach ($rackPanels as $panel) {
                $panelIds[] = (int) $panel['id'];
            }
        }
        $count = 0;
        foreach (array_unique($panelIds) as $panelId) {
            $panel = $this->panel($panelId);
            if ($panel === null) {
                continue;
            }
            foreach ($panel['port_items'] as $port) {
                if ((int) ($port['front_connection']['device_id'] ?? 0) === $deviceId) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function demoActiveDeviceInterfaces(int $deviceId): array
    {
        $panelIds = [1, 2, 3, 10];
        foreach ($_SESSION['demo_panels'] ?? [] as $rackPanels) {
            foreach ($rackPanels as $panel) {
                $panelIds[] = (int) $panel['id'];
            }
        }
        $interfaces = [];
        foreach (array_unique($panelIds) as $panelId) {
            $panel = $this->panel($panelId);
            if ($panel === null) {
                continue;
            }
            foreach ($panel['port_items'] as $port) {
                $front = $port['front_connection'] ?? null;
                if ((int) ($front['device_id'] ?? 0) !== $deviceId) {
                    continue;
                }
                $interfaces[] = [
                    'id' => (int) $front['interface_id'],
                    'name' => $front['interface_name'],
                    'interface_type' => $front['interface_type'],
                    'speed_label' => $front['interface_speed'] ?? null,
                    'destination' => sprintf('%s · %s · %s · Port %02d', $panel['location'], $panel['rack'], $panel['code'], (int) $port['number']),
                    'port_id' => (int) $port['id'],
                ];
            }
        }
        return $interfaces;
    }

    private function findDemoActiveDevice(int $activeDeviceId): ?array
    {
        $device = $this->demoActiveDeviceFixtures()[$activeDeviceId] ?? $_SESSION['demo_active_devices'][$activeDeviceId] ?? null;
        if ($device === null) {
            return null;
        }
        return array_replace($device, $_SESSION['demo_active_device_updates'][$activeDeviceId] ?? []);
    }

    private function activeDevicesForRack(int $rackId): array
    {
        $archived = array_map('intval', $_SESSION['demo_archived_active_devices'] ?? []);
        $ids = array_unique([...array_keys($this->demoActiveDeviceFixtures()), ...array_keys($_SESSION['demo_active_devices'] ?? [])]);
        $devices = [];
        foreach ($ids as $id) {
            if (in_array((int) $id, $archived, true)) {
                continue;
            }
            $device = $this->findDemoActiveDevice((int) $id);
            if ($device === null || (int) $device['rack_id'] !== $rackId) {
                continue;
            }
            $interfaces = $this->demoActiveDeviceInterfaces((int) $id);
            $devices[] = [
                'id' => (int) $device['id'],
                'code' => $device['code'],
                'name' => $device['name'],
                'device_type' => $device['device_type'],
                'vendor' => $device['vendor'],
                'model' => $device['model'] ?? null,
                'management_address' => $device['management_address'] ?? null,
                'notes' => $device['notes'] ?? null,
                'interface_count' => count($interfaces),
                'connected_count' => count($interfaces),
                'interfaces' => $interfaces,
            ];
        }
        return $devices;
    }

    public function updateActiveDevice(int $activeDeviceId, array $input): array
    {
        if ($this->findDemoActiveDevice($activeDeviceId) === null) {
            throw new \RuntimeException('Active device not found');
        }
        $update = [
            'name' => trim((string) $input['name']),
            'device_type' => strtoupper(trim((string) $input['device_type'])),
            'vendor' => trim((string) $input['vendor']),
            'model' => trim((string) ($input['model'] ?? '')) ?: null,
            'management_address' => trim((string) ($input['management_address'] ?? '')) ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
        $_SESSION['demo_active_device_updates'][$activeDeviceId] = $update;
        return ['id' => $activeDeviceId] + $update;
    }

    public function archiveActiveDevice(int $activeDeviceId): array
    {
        $device = $this->findDemoActiveDevice($activeDeviceId);
        if ($device === null) {
            throw new \RuntimeException('Active device not found');
        }
        if ($this->demoActiveDeviceConnectionCount($activeDeviceId) > 0) {
            throw new ResourceInUseException('active_device_connected');
        }
        $_SESSION['demo_archived_active_devices'][] = $activeDeviceId;
        return ['id' => $activeDeviceId, 'code' => $device['code'], 'archived' => true];
    }

    private function rackItemsForRack(int $rackId): array
    {
        $archived = array_map('intval', $_SESSION['demo_archived_rack_items'] ?? []);
        $items = [];
        foreach ($_SESSION['demo_rack_items'][$rackId] ?? [] as $item) {
            if (in_array((int) $item['id'], $archived, true)) {
                continue;
            }
            $items[] = array_replace($item, $_SESSION['demo_rack_item_updates'][$item['id']] ?? []);
        }
        return array_map(static fn (array $item): array => [
            'id' => (int) $item['id'],
            'code' => $item['kind'],
            'name' => $item['name'],
            'kind' => $item['kind'],
            'notes' => $item['notes'] ?? null,
            'start' => (int) $item['rack_unit_start'],
            'height' => (int) $item['rack_unit_height'],
            'type' => 'rack_item',
            'tone' => match ($item['kind']) {
                'POWER', 'UPS' => 'amber',
                'ACTIVE_DEVICE' => 'cyan',
                'PATCH_PANEL' => 'violet',
                default => 'slate',
            },
        ], $items);
    }

    private function findDemoRackItem(int $rackItemId): ?array
    {
        foreach ($_SESSION['demo_rack_items'] ?? [] as $rackItems) {
            foreach ($rackItems as $item) {
                if ((int) $item['id'] === $rackItemId) {
                    return $item;
                }
            }
        }
        return null;
    }

    public function disconnectActiveDeviceInterface(int $interfaceId): array
    {
        $panelIds = [1, 2, 3, 10];
        foreach ($_SESSION['demo_panels'] ?? [] as $rackPanels) {
            foreach ($rackPanels as $panel) {
                $panelIds[] = (int) $panel['id'];
            }
        }
        foreach (array_unique($panelIds) as $panelId) {
            $panel = $this->panel($panelId);
            if ($panel === null) {
                continue;
            }
            foreach ($panel['port_items'] as $port) {
                if ((int) ($port['front_connection']['interface_id'] ?? 0) !== $interfaceId) {
                    continue;
                }
                $portId = (int) $port['id'];
                $location = $this->findDemoPortLocation($portId);
                if ($location !== null) {
                    [$rackId, $panelIndex, $portIndex] = $location;
                    $stored =& $_SESSION['demo_panels'][$rackId][$panelIndex]['port_items'][$portIndex];
                    $stored['front_connection'] = null;
                    $stored['front_destination'] = null;
                    $stored['has_front_connection'] = false;
                    $stored['destination'] = $stored['rear_destination'] ?? null;
                    $stored['status'] = ($stored['has_patch_cord'] || $stored['has_termination']) ? 'occupied' : 'available';
                    unset($stored);
                } else {
                    $update = $_SESSION['demo_port_updates'][$portId] ?? [];
                    $update['front_connection'] = null;
                    $_SESSION['demo_port_updates'][$portId] = $update;
                }
                return ['id' => $interfaceId, 'disconnected' => true];
            }
        }
        throw new \RuntimeException('Device interface not found');
    }

    public function createRackItem(int $rackId, array $input): array
    {
        if ($this->rack($rackId) === null) {
            throw new \RuntimeException('Rack not found');
        }
        $id = 8000 + count($_SESSION['demo_rack_items'][$rackId] ?? []) + (int) ($_SESSION['demo_rack_item_sequence'] ?? 0);
        $_SESSION['demo_rack_item_sequence'] = (int) ($_SESSION['demo_rack_item_sequence'] ?? 0) + 1;
        $item = [
            'id' => $id,
            'rack_id' => $rackId,
            'name' => trim((string) $input['name']),
            'kind' => strtoupper(trim((string) $input['kind'])),
            'rack_unit_start' => (int) $input['rack_unit_start'],
            'rack_unit_height' => (int) $input['rack_unit_height'],
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
        $_SESSION['demo_rack_items'][$rackId][] = $item;
        return $item;
    }

    public function updateRackItem(int $rackItemId, array $input): array
    {
        if ($this->findDemoRackItem($rackItemId) === null) {
            throw new \RuntimeException('Rack item not found');
        }
        $update = [
            'name' => trim((string) $input['name']),
            'kind' => strtoupper(trim((string) $input['kind'])),
            'rack_unit_start' => (int) $input['rack_unit_start'],
            'rack_unit_height' => (int) $input['rack_unit_height'],
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
        $_SESSION['demo_rack_item_updates'][$rackItemId] = $update;
        return ['id' => $rackItemId] + $update;
    }

    public function archiveRackItem(int $rackItemId): array
    {
        $item = $this->findDemoRackItem($rackItemId);
        if ($item === null) {
            throw new \RuntimeException('Rack item not found');
        }
        $_SESSION['demo_archived_rack_items'][] = $rackItemId;
        return ['id' => $rackItemId, 'name' => $item['name'], 'archived' => true];
    }

    public function updatePort(int $portId, array $input): array
    {
        $location = $this->findDemoPortLocation($portId);
        $currentPort = null;
        if ($location !== null) {
            [$rackId, $panelIndex, $portIndex] = $location;
            $currentPort = $_SESSION['demo_panels'][$rackId][$panelIndex]['port_items'][$portIndex];
        } else {
            foreach ([1, 2, 3, 10] as $panelId) {
                foreach ($this->panel($panelId)['port_items'] as $candidate) {
                    if ((int) $candidate['id'] === $portId) {
                        $currentPort = $candidate;
                        break 2;
                    }
                }
            }
        }
        if ($currentPort === null) {
            throw new \RuntimeException('Port not found');
        }

        $connectorTypeId = (int) ($input['connector_type_id'] ?? $currentPort['connector_type_id'] ?? 4);
        $label = trim((string) ($input['label'] ?? '')) ?: null;
        $highlightColor = trim((string) ($input['highlight_color'] ?? '')) ?: null;
        $remoteEndpoint = $currentPort['manual_remote_endpoint'] ?? null;
        $rearDestination = $currentPort['rear_destination'] ?? null;
        $notes = trim((string) ($input['notes'] ?? '')) ?: null;
        $administrativeStatus = strtoupper(trim((string) $input['administrative_status']));
        $rearMode = strtoupper(trim((string) ($input['rear_connection_mode'] ?? 'UNCHANGED')));
        if ($rearMode === 'NONE') {
            $this->removeDemoRearConnection($portId);
            $rearDestination = null;
        }
        $frontMode = strtoupper(trim((string) ($input['front_connection_mode'] ?? 'UNCHANGED')));
        $frontConnection = $currentPort['front_connection'] ?? null;
        if ($frontMode !== 'UNCHANGED' && ($frontConnection['type'] ?? null) === 'PORT') {
            $previousTarget = $this->findDemoPortLocation((int) ($frontConnection['destination_port_id'] ?? 0));
            if ($previousTarget !== null) {
                [$previousRackId, $previousPanelIndex, $previousPortIndex] = $previousTarget;
                $remotePort =& $_SESSION['demo_panels'][$previousRackId][$previousPanelIndex]['port_items'][$previousPortIndex];
                $remotePort['front_connection'] = null;
                $remotePort['front_destination'] = null;
                $remotePort['has_front_connection'] = false;
                $remotePort['destination'] = $remotePort['rear_destination'] ?? null;
                $remotePort['status'] = ($remotePort['has_patch_cord'] || $remotePort['has_termination']) ? 'occupied' : 'available';
                unset($remotePort);
            }
        }
        if ($frontMode === 'NONE') {
            $frontConnection = null;
        } elseif ($frontMode === 'DEVICE') {
            $deviceId = (int) ($input['active_device_id'] ?? 0);
            $devices = array_column($this->activeDeviceOptions()['devices'], null, 'id');
            if ($deviceId > 0 && isset($devices[$deviceId])) {
                $device = $devices[$deviceId];
            } else {
                $deviceRackId = (int) $input['active_device_rack_id'];
                $racks = array_column($this->activeDeviceOptions()['racks'], null, 'id');
                $rack = $racks[$deviceRackId] ?? null;
                if ($rack === null) {
                    throw new \RuntimeException('Rack not found');
                }
                $deviceId = 900 + count($_SESSION['demo_active_devices'] ?? []);
                $device = [
                    'id' => $deviceId,
                    'rack_id' => $deviceRackId,
                    'code' => sprintf('DEV-%06d', $deviceId),
                    'name' => trim((string) $input['active_device_name']),
                    'device_type' => strtoupper(trim((string) $input['active_device_type'])),
                    'vendor' => trim((string) $input['active_device_vendor']),
                    'model' => trim((string) ($input['active_device_model'] ?? '')) ?: null,
                    'rack' => $rack['code'],
                    'room' => $rack['room'],
                    'location' => $rack['location'],
                ];
                $_SESSION['demo_active_devices'][$deviceId] = $device;
            }
            $deviceLabel = trim($device['vendor'] . ' ' . $device['name']) . ($device['model'] ? ' (' . $device['model'] . ')' : '');
            $frontDestination = implode(' · ', [$device['location'], $device['room'], $device['rack'], $deviceLabel, trim((string) $input['active_interface_name'])]);
            $frontConnection = [
                'id' => $portId,
                'device_id' => $deviceId,
                'device_rack_id' => (int) $device['rack_id'],
                'device_code' => $device['code'],
                'device_name' => $device['name'],
                'device_type' => $device['device_type'],
                'device_vendor' => $device['vendor'],
                'device_model' => $device['model'],
                'interface_id' => $portId,
                'interface_name' => trim((string) $input['active_interface_name']),
                'interface_type' => strtoupper(trim((string) $input['active_interface_type'])),
                'interface_speed' => trim((string) ($input['active_interface_speed'] ?? '')) ?: null,
                'patch_cord_label' => trim((string) ($input['front_patch_cord_label'] ?? '')) ?: null,
                'notes' => trim((string) ($input['front_connection_notes'] ?? '')) ?: null,
                'label' => $frontDestination,
                'type' => 'DEVICE',
            ];
        } elseif ($frontMode === 'PORT') {
            $destinationPortId = (int) ($input['front_destination_port_id'] ?? 0);
            $destinationLocation = $this->findDemoPortLocation($destinationPortId);
            if ($location === null || $destinationLocation === null) {
                throw new \RuntimeException('Front destination port was not found');
            }
            [$destinationRackId, $destinationPanelIndex, $destinationPortIndex] = $destinationLocation;
            if ((int) $destinationRackId === (int) $rackId) {
                throw new \RuntimeException('Select a front port in another rack');
            }
            $destinationPort =& $_SESSION['demo_panels'][$destinationRackId][$destinationPanelIndex]['port_items'][$destinationPortIndex];
            if ($destinationPort['has_front_connection'] || in_array($destinationPort['status'], ['blocked', 'damaged'], true)) {
                throw new \RuntimeException('The selected front destination port is already occupied');
            }
            $sourceDescription = $this->describeDemoPort($portId);
            $destinationDescription = $this->describeDemoPort($destinationPortId);
            $connectionId = 1 + count($_SESSION['demo_front_panel_connections'] ?? []);
            $patchCordLabel = trim((string) ($input['front_patch_cord_label'] ?? '')) ?: null;
            $frontNotes = trim((string) ($input['front_connection_notes'] ?? '')) ?: null;
            $frontConnection = [
                'id' => $connectionId,
                'type' => 'PORT',
                'destination_port_id' => $destinationPortId,
                'destination_rack_id' => (int) $destinationRackId,
                'patch_cord_label' => $patchCordLabel,
                'notes' => $frontNotes,
                'label' => $destinationDescription,
            ];
            $destinationPort['front_connection'] = [
                'id' => $connectionId,
                'type' => 'PORT',
                'destination_port_id' => $portId,
                'destination_rack_id' => (int) $rackId,
                'patch_cord_label' => $patchCordLabel,
                'notes' => $frontNotes,
                'label' => $sourceDescription,
            ];
            $destinationPort['front_destination'] = $sourceDescription;
            $destinationPort['has_front_connection'] = true;
            $destinationPort['destination'] = $destinationPort['rear_destination'] ?: $sourceDescription;
            $destinationPort['status'] = 'occupied';
            $_SESSION['demo_front_panel_connections'][$connectionId] = [
                'id' => $connectionId,
                'source_port_id' => $portId,
                'destination_port_id' => $destinationPortId,
            ];
            unset($destinationPort);
        }
        $update = ['connector_type_id' => $connectorTypeId, 'label' => $label, 'highlight_color' => $highlightColor, 'remote_endpoint_label' => $remoteEndpoint, 'notes' => $notes, 'administrative_status' => $administrativeStatus, 'front_connection' => $frontConnection, 'rear_connection_mode' => $rearMode];
        $_SESSION['demo_port_updates'][$portId] = $update;

        if ($location !== null) {
            $port =& $_SESSION['demo_panels'][$rackId][$panelIndex]['port_items'][$portIndex];
            $port['connector_type_id'] = $connectorTypeId;
            $port['connector'] = array_column($this->connectorTypes(), 'code', 'id')[$connectorTypeId] ?? $port['connector'];
            $port['label'] = $label;
            $port['highlight_color'] = $highlightColor;
            $port['manual_remote_endpoint'] = $remoteEndpoint;
            $port['notes'] = $notes;
            $port['administrative_status'] = $administrativeStatus;
            $port['rear_destination'] = $rearDestination;
            $port['front_connection'] = $frontConnection;
            $port['front_destination'] = $frontConnection['label'] ?? null;
            $port['destination'] = $port['rear_destination'] ?: $port['front_destination'];
            $port['has_front_connection'] = $frontConnection !== null;
            $port['status'] = $administrativeStatus === 'AVAILABLE' ? ($port['has_patch_cord'] || $port['has_termination'] || $port['has_front_connection'] ? 'occupied' : 'available') : strtolower($administrativeStatus);
        }

        return ['id' => $portId] + $update;
    }

    private function removeDemoRearConnection(int $portId): void
    {
        $cords = $_SESSION['demo_patch_cords'] ?? [];
        $index = null;
        foreach ($cords as $i => $cord) {
            if ((int) $cord['source_port_id'] === $portId || (int) $cord['destination_port_id'] === $portId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $cord = $cords[$index];
        unset($cords[$index]);
        $_SESSION['demo_patch_cords'] = array_values($cords);

        foreach (['source_port_id', 'destination_port_id'] as $key) {
            $location = $this->findDemoPortLocation((int) $cord[$key]);
            if ($location === null) {
                continue;
            }
            [$rackId, $panelIndex, $portIndex] = $location;
            $port =& $_SESSION['demo_panels'][$rackId][$panelIndex]['port_items'][$portIndex];
            $port['has_patch_cord'] = false;
            $port['connection_code'] = null;
            $port['rear_destination'] = null;
            $port['destination'] = $port['front_destination'] ?? null;
            $port['status'] = ($port['has_front_connection'] ?? false) ? 'occupied' : strtolower((string) ($port['administrative_status'] ?? 'AVAILABLE'));
            unset($port);
        }

        foreach ($this->rearFiberRoutes((int) $cord['source_port_id']) as $candidate) {
            if ($candidate['key'] === $cord['route_key']) {
                foreach ($this->cables() as $cable) {
                    if ($cable['code'] === $candidate['cable_path']) {
                        $cableId = (int) $cable['id'];
                        $_SESSION['demo_rear_route_usage'][$cableId] = max(0, (int) ($_SESSION['demo_rear_route_usage'][$cableId] ?? 0) - 1);
                        break;
                    }
                }
                break;
            }
        }
    }

    public function rearFiberRoutes(int $portId): array
    {
        $sourceContext = $this->demoPortContext($portId);
        if ($sourceContext === null) {
            return [];
        }
        $routes = [];
        foreach ($this->cables() as $cable) {
            $aEndpoint = $this->demoCableEndpoint($cable, 'source');
            $zEndpoint = $this->demoCableEndpoint($cable, 'destination');
            $aMatches = $aEndpoint !== null && $this->demoEndpointMatches($aEndpoint, $sourceContext);
            $zMatches = $zEndpoint !== null && $this->demoEndpointMatches($zEndpoint, $sourceContext);
            if (!$aMatches && !$zMatches) {
                continue;
            }
            $destination = $aMatches ? $zEndpoint : $aEndpoint;
            if ($destination === null) {
                continue;
            }
            $used = (int) ($cable['used'] ?? 0) + (int) ($_SESSION['demo_rear_route_usage'][(int) $cable['id']] ?? 0);
            $capacity = (int) $cable['fiber_count'];
            $freeFibers = max(0, $capacity - $used);
            $status = strtoupper((string) ($cable['operational_status'] ?? $cable['status'] ?? 'PLANNED'));
            $selectable = $status === 'ACTIVE' && $freeFibers > 0;
            $availability = $freeFibers <= 0 ? 'full' : strtolower($status);
            if ($selectable) {
                $availability = 'available';
            }
            $key = hash('sha256', implode('|', [$portId, $sourceContext['rack_id'], $destination['key'], $cable['id']]));
            $routes[] = [
                'key' => $key,
                'destination_location_id' => $destination['location_id'],
                'destination_location_code' => $destination['location_code'],
                'destination_location_name' => $destination['location_name'],
                'destination_server_room_id' => $destination['server_room_id'],
                'destination_rack_id' => $destination['rack_id'],
                'destination_label' => $destination['label'],
                'cable_codes' => [$cable['code']],
                'cable_path' => $cable['code'],
                'medium' => $cable['medium'],
                'fiber_capacity' => $capacity,
                'free_fibers' => $freeFibers,
                'used_fibers' => $used,
                'segment_count' => (int) ($cable['segments'] ?? 1),
                'length_m' => (float) ($cable['length_m'] ?? 0),
                'availability' => $availability,
                'selectable' => $selectable,
                'label' => $cable['code'] . ' · ' . $destination['label'],
            ];
        }
        usort($routes, static fn (array $left, array $right): int => ($right['selectable'] <=> $left['selectable']) ?: strcmp($left['label'], $right['label']));
        return $routes;
    }

    public function frontPortTargets(int $portId, string $query = ''): array
    {
        $sourcePanel = null;
        $sourcePort = null;
        foreach ($this->allPanels() as $panel) {
            foreach ($panel['port_items'] as $port) {
                if ((int) $port['id'] === $portId) {
                    $sourcePanel = $panel;
                    $sourcePort = $port;
                    break 2;
                }
            }
        }
        if ($sourcePanel === null || $sourcePort === null) {
            return [];
        }
        $currentDestinationId = ($sourcePort['front_connection']['type'] ?? null) === 'PORT'
            ? (int) ($sourcePort['front_connection']['destination_port_id'] ?? 0)
            : 0;
        $query = mb_strtolower(trim($query));
        $targets = [];
        foreach ($this->allPanels() as $panel) {
            if ((int) $panel['rack_id'] === (int) $sourcePanel['rack_id']) {
                continue;
            }
            foreach ($panel['port_items'] as $port) {
                if ((int) $port['id'] === $portId || ($port['has_front_connection'] && (int) $port['id'] !== $currentDestinationId) || in_array($port['status'], ['blocked', 'damaged'], true)) {
                    continue;
                }
                $haystack = mb_strtolower(implode(' ', [$panel['location'], $panel['room'], $panel['rack'], $panel['code'], $panel['name'], $port['label'] ?? '', $port['number']]));
                if ($query !== '' && !str_contains($haystack, $query)) {
                    continue;
                }
                $targets[] = [
                    'id' => (int) $port['id'],
                    'port_number' => (int) $port['number'],
                    'label' => $port['label'],
                    'connector' => $port['connector'],
                    'panel_id' => (int) $panel['id'],
                    'panel_code' => $panel['code'],
                    'panel_name' => $panel['name'],
                    'rack_id' => (int) $panel['rack_id'],
                    'rack' => $panel['rack'],
                    'rack_name' => $panel['rack'],
                    'room' => $panel['room'],
                    'location' => $panel['location'],
                ];
            }
        }
        return array_slice($targets, 0, 160);
    }

    public function connectionTargets(int $portId, string $query = '', string $routeKey = ''): array
    {
        $route = null;
        foreach ($this->rearFiberRoutes($portId) as $candidate) {
            if (hash_equals($candidate['key'], $routeKey)) {
                $route = $candidate;
                break;
            }
        }
        if ($route === null || !$route['selectable']) {
            return [];
        }
        $query = mb_strtolower(trim($query));
        $targets = [];
        foreach ($this->allPanels() as $panel) {
            $panelContext = $this->demoPanelContext($panel);
            $matchesLocation = $panelContext !== null
                && (int) $panelContext['location_id'] === (int) $route['destination_location_id'];
            $matchesRoom = (int) ($route['destination_server_room_id'] ?? 0) === 0
                || (int) ($panelContext['server_room_id'] ?? 0) === (int) $route['destination_server_room_id'];
            $matchesRack = (int) ($route['destination_rack_id'] ?? 0) === 0
                || (int) ($panelContext['rack_id'] ?? 0) === (int) $route['destination_rack_id'];
            if (!$matchesLocation || !$matchesRoom || !$matchesRack) {
                continue;
            }
            foreach ($panel['port_items'] as $port) {
                if ((int) $port['id'] === $portId || $port['has_patch_cord'] || in_array($port['status'], ['blocked', 'damaged'], true)) {
                    continue;
                }
                $haystack = mb_strtolower(implode(' ', [$panel['location'], $panel['room'], $panel['rack'], $panel['code'], $panel['name'], $port['label'] ?? '', $port['number']]));
                if ($query !== '' && !str_contains($haystack, $query)) {
                    continue;
                }
                $targets[] = [
                    'id' => (int) $port['id'],
                    'panel_id' => (int) $panel['id'],
                    'port_number' => (int) $port['number'],
                    'label' => $port['label'],
                    'connector' => $port['connector'],
                    'panel_code' => $panel['code'],
                    'panel_name' => $panel['name'],
                    'rack' => $panel['rack'],
                    'room' => $panel['room'],
                    'location' => $panel['location'],
                ];
            }
        }
        return array_slice($targets, 0, 100);
    }

    public function connectPorts(int $sourcePortId, int $destinationPortId, array $input): array
    {
        if ($sourcePortId === $destinationPortId) {
            throw new \RuntimeException('Source and destination ports must be different');
        }
        $sourceLocation = $this->findDemoPortLocation($sourcePortId);
        $destinationLocation = $this->findDemoPortLocation($destinationPortId);
        if ($sourceLocation === null || $destinationLocation === null) {
            throw new \RuntimeException('One or both ports were not found');
        }
        $route = null;
        foreach ($this->rearFiberRoutes($sourcePortId) as $candidate) {
            if (hash_equals($candidate['key'], trim((string) ($input['rear_route_key'] ?? '')))) {
                $route = $candidate;
                break;
            }
        }
        if ($route === null || !$route['selectable']) {
            throw new \RuntimeException('The selected physical fiber route is no longer available');
        }
        $destinationContext = $this->demoPortContext($destinationPortId);
        $matchesLocation = $destinationContext !== null
            && (int) $destinationContext['location_id'] === (int) $route['destination_location_id'];
        $matchesRoom = (int) ($route['destination_server_room_id'] ?? 0) === 0
            || (int) ($destinationContext['server_room_id'] ?? 0) === (int) $route['destination_server_room_id'];
        $matchesRack = (int) ($route['destination_rack_id'] ?? 0) === 0
            || (int) ($destinationContext['rack_id'] ?? 0) === (int) $route['destination_rack_id'];
        if (!$matchesLocation || !$matchesRoom || !$matchesRack) {
            throw new \RuntimeException('The destination port is not located at the selected route endpoint');
        }
        [$sourceRackId, $sourcePanelIndex, $sourcePortIndex] = $sourceLocation;
        [$destinationRackId, $destinationPanelIndex, $destinationPortIndex] = $destinationLocation;
        $source =& $_SESSION['demo_panels'][$sourceRackId][$sourcePanelIndex]['port_items'][$sourcePortIndex];
        $destination =& $_SESSION['demo_panels'][$destinationRackId][$destinationPanelIndex]['port_items'][$destinationPortIndex];
        if ($source['has_patch_cord'] || $destination['has_patch_cord'] || in_array($source['status'], ['blocked', 'damaged'], true) || in_array($destination['status'], ['blocked', 'damaged'], true)) {
            throw new \RuntimeException('One of the selected ports is already occupied');
        }
        $id = 1 + count($_SESSION['demo_patch_cords'] ?? []);
        $code = sprintf('RFC-%06d', $id);
        $source['status'] = 'occupied';
        $source['has_patch_cord'] = true;
        $source['connection_code'] = $code;
        $destination['status'] = 'occupied';
        $destination['has_patch_cord'] = true;
        $destination['connection_code'] = $code;
        $source['rear_destination'] = $this->describeDemoPort($destinationPortId) . ' · via ' . $route['cable_path'];
        $destination['rear_destination'] = $this->describeDemoPort($sourcePortId) . ' · via ' . $route['cable_path'];
        $source['destination'] = $source['rear_destination'];
        $destination['destination'] = $destination['rear_destination'];
        foreach ($this->cables() as $cable) {
            if ($cable['code'] === $route['cable_path']) {
                $_SESSION['demo_rear_route_usage'][(int) $cable['id']] = 1 + (int) ($_SESSION['demo_rear_route_usage'][(int) $cable['id']] ?? 0);
                break;
            }
        }
        $_SESSION['demo_patch_cords'][] = ['id' => $id, 'code' => $code, 'source_port_id' => $sourcePortId, 'destination_port_id' => $destinationPortId, 'route_key' => $route['key'], 'notes' => trim((string) ($input['notes'] ?? ''))];

        return ['id' => $id, 'code' => $code, 'source_port_id' => $sourcePortId, 'destination_port_id' => $destinationPortId, 'route_key' => $route['key'], 'route_label' => $route['label'], 'status' => 'active'];
    }

    public function createCable(array $input): array
    {
        $source = $this->demoInputEndpoint($input, 'source');
        $destination = $this->demoInputEndpoint($input, 'destination');
        $id = 400 + count($_SESSION['demo_cables'] ?? []);
        $lengthMeters = (float) $input['length_m'];
        $cable = [
            'id' => $id,
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'medium' => strtoupper(trim((string) $input['medium'])),
            'fiber_count' => (int) $input['fiber_count'],
            'used' => 0,
            'status' => strtolower((string) ($input['operational_status'] ?? 'PLANNED')),
            'operational_status' => strtoupper((string) ($input['operational_status'] ?? 'PLANNED')),
            'source' => $source['label'],
            'destinations' => [$destination['label']],
            'length' => number_format($lengthMeters / 1000, 2) . ' km',
            'length_m' => $lengthMeters,
            'segments' => 1,
            'updated' => 'Now',
            'accent' => 'blue',
            'source_location_id' => $source['location_id'],
            'destination_location_id' => $destination['location_id'],
            'source_endpoint_key' => $source['key'],
            'destination_endpoint_key' => $destination['key'],
            'segment_code' => strtoupper(trim((string) $input['code'])) . '-S01',
        ];
        $_SESSION['demo_cables'][] = $cable;
        return $cable;
    }

    public function updateCable(int $cableId, array $input): array
    {
        $source = $this->demoInputEndpoint($input, 'source');
        $destination = $this->demoInputEndpoint($input, 'destination');
        $lengthMeters = (float) $input['length_m'];
        $record = [
            'id' => $cableId,
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'medium' => strtoupper(trim((string) $input['medium'])),
            'fiber_count' => (int) $input['fiber_count'],
            'status' => strtolower((string) $input['operational_status']),
            'operational_status' => strtoupper((string) $input['operational_status']),
            'source_location_id' => $source['location_id'],
            'destination_location_id' => $destination['location_id'],
            'source_endpoint_key' => $source['key'],
            'destination_endpoint_key' => $destination['key'],
            'source' => $source['label'],
            'destinations' => [$destination['label']],
            'length_m' => $lengthMeters,
            'length' => number_format($lengthMeters / 1000, 2) . ' km',
        ];
        $_SESSION['demo_cable_updates'][$cableId] = $record;
        return $record;
    }

    public function archiveCable(int $cableId): array
    {
        $cable = $this->findById($this->cables(), $cableId);
        if ($cable === null) {
            throw new \RuntimeException('Cable not found');
        }
        if ((int) ($cable['used'] ?? 0) > 0 || (int) ($_SESSION['demo_rear_route_usage'][$cableId] ?? 0) > 0) {
            throw new ResourceInUseException('cable_has_used_fibers');
        }
        $_SESSION['demo_archived_cables'][] = $cableId;
        return ['id' => $cableId, 'code' => $cable['code'], 'archived' => true];
    }

    public function tracePort(int $portId): ?array
    {
        $selectedPanel = null;
        $selectedPort = null;
        foreach ([1, 2, 3] as $panelId) {
            $panel = $this->panel($panelId);
            foreach ($panel['port_items'] as $port) {
                if ($port['id'] === $portId) {
                    $selectedPanel = $panel;
                    $selectedPort = $port;
                    break 2;
                }
            }
        }

        if ($selectedPanel === null || $selectedPort === null || $selectedPort['status'] !== 'occupied') {
            return null;
        }

        $number = (int) $selectedPort['number'];
        $steps = [[
            'type' => 'port',
            'label' => sprintf('%s · Port %02d', $selectedPanel['code'], $number),
            'detail' => $selectedPanel['location'] . ' · ' . $selectedPanel['rack'],
            'status' => 'start',
        ]];

        if ($selectedPanel['id'] === 3) {
            $steps[] = ['type' => 'fiber', 'label' => sprintf('SEG-M01-C · Fiber %02d', $number), 'detail' => 'CBL-WAW-001 · 24J SM', 'status' => 'active'];
            $steps[] = ['type' => 'splice', 'label' => sprintf('Metro Splice M01 · Splice %02d', $number + 24), 'detail' => 'Tray 3 · fusion splice', 'status' => 'active'];
            $steps[] = ['type' => 'fiber', 'label' => sprintf('SEG-A-M01 · Fiber %02d', $number + 24), 'detail' => 'CBL-WAW-001 · 48J SM', 'status' => 'active'];
            $steps[] = ['type' => 'port', 'label' => sprintf('PP-WAW-01 · Port %02d', $number + 24), 'detail' => 'Warsaw Core · R01', 'status' => 'destination'];
        } elseif ($selectedPanel['id'] === 2) {
            $steps[] = ['type' => 'fiber', 'label' => sprintf('SEG-M01-B · Fiber %02d', $number), 'detail' => 'CBL-WAW-001 · 24J SM', 'status' => 'active'];
            $steps[] = ['type' => 'splice', 'label' => sprintf('Metro Splice M01 · Splice %02d', $number), 'detail' => 'Tray 1 · fusion splice', 'status' => 'active'];
            $steps[] = ['type' => 'fiber', 'label' => sprintf('SEG-A-M01 · Fiber %02d', $number), 'detail' => 'CBL-WAW-001 · 48J SM', 'status' => 'active'];
            $steps[] = ['type' => 'port', 'label' => sprintf('PP-WAW-01 · Port %02d', $number), 'detail' => 'Warsaw Core · R01', 'status' => 'destination'];
        } else {
            $targetPanel = $number <= 24 ? 'PP-NORTH-01' : 'PP-LAB-01';
            $targetLocation = $number <= 24 ? 'North Office' : 'Research Campus';
            $targetNumber = $number <= 24 ? $number : $number - 24;
            $targetSegment = $number <= 24 ? 'SEG-M01-B' : 'SEG-M01-C';
            $steps[] = ['type' => 'fiber', 'label' => sprintf('SEG-A-M01 · Fiber %02d', $number), 'detail' => 'CBL-WAW-001 · 48J SM', 'status' => 'active'];
            $steps[] = ['type' => 'splice', 'label' => sprintf('Metro Splice M01 · Splice %02d', $number), 'detail' => 'Fusion splice', 'status' => 'active'];
            $steps[] = ['type' => 'fiber', 'label' => sprintf('%s · Fiber %02d', $targetSegment, $targetNumber), 'detail' => 'CBL-WAW-001 · 24J SM', 'status' => 'active'];
            $steps[] = ['type' => 'port', 'label' => sprintf('%s · Port %02d', $targetPanel, $targetNumber), 'detail' => $targetLocation, 'status' => $number <= 36 ? 'destination' : 'open'];
        }

        return [
            'source_port_id' => $portId,
            'status' => 'complete',
            'total_loss' => $selectedPort['loss'],
            'steps' => $steps,
        ];
    }

    private function createUpsDeviceRecord(int $upsDeviceId, int $serverRoomId, string $code, array $input): array
    {
        return [
            'id' => $upsDeviceId,
            'server_room_id' => $serverRoomId,
            'code' => $code,
            'name' => trim((string) $input['name']),
            'manufacturer' => trim((string) ($input['manufacturer'] ?? '')) ?: null,
            'model' => trim((string) ($input['model'] ?? '')) ?: null,
            'serial_number' => trim((string) ($input['serial_number'] ?? '')) ?: null,
            'rated_power_va' => ($input['rated_power_va'] ?? '') === '' ? null : (int) $input['rated_power_va'],
            'rated_power_w' => ($input['rated_power_w'] ?? '') === '' ? null : (int) $input['rated_power_w'],
            'ip_address' => trim((string) ($input['ip_address'] ?? '')) ?: null,
            'management_url' => trim((string) ($input['management_url'] ?? '')) ?: null,
            'battery_replaced_at' => trim((string) ($input['battery_replaced_at'] ?? '')) ?: null,
            'battery_replacement_interval_months' => (int) ($input['battery_replacement_interval_months'] ?? 36),
            'battery_count' => ($input['battery_count'] ?? '') === '' ? null : (int) $input['battery_count'],
            'battery_type' => trim((string) ($input['battery_type'] ?? '')) ?: null,
            'operational_status' => strtolower(trim((string) ($input['operational_status'] ?? 'ACTIVE'))),
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
    }

    private function normalizeDemoUpsDevice(array $upsDevice): array
    {
        $batteryDueAt = null;
        $batteryState = 'unknown';
        if (!empty($upsDevice['battery_replaced_at'])) {
            $replacementDate = new \DateTimeImmutable((string) $upsDevice['battery_replaced_at']);
            $batteryDueAt = $replacementDate
                ->modify('+' . (int) $upsDevice['battery_replacement_interval_months'] . ' months')
                ->format('Y-m-d');
            $today = new \DateTimeImmutable('today');
            $dueDate = new \DateTimeImmutable($batteryDueAt);
            $batteryState = $dueDate < $today
                ? 'overdue'
                : ($dueDate <= $today->modify('+90 days') ? 'due_soon' : 'current');
        }
        $upsDevice['battery_due_at'] = $batteryDueAt;
        $upsDevice['battery_state'] = $batteryState;
        $upsDevice['operational_status'] = strtolower((string) $upsDevice['operational_status']);
        return $upsDevice;
    }

    private function demoCableUsesEndpoint(string $endpointKey): bool
    {
        foreach ($this->cables() as $cable) {
            if (
                strtoupper((string) ($cable['source_endpoint_key'] ?? '')) === $endpointKey
                || strtoupper((string) ($cable['destination_endpoint_key'] ?? '')) === $endpointKey
            ) {
                return true;
            }
        }
        return false;
    }

    private function findById(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    private function assetImages(string $entityType, int $entityId): array
    {
        $images = array_filter(
            $_SESSION['demo_asset_images'] ?? [],
            static fn (array $image): bool => $image['entity_type'] === $entityType && (int) $image['entity_id'] === $entityId,
        );
        usort($images, static fn (array $first, array $second): int => (int) $second['id'] <=> (int) $first['id']);
        return array_map(fn (array $image): array => $this->normalizeAssetImage($image), $images);
    }

    private function normalizeAssetImage(array $image): array
    {
        $image['id'] = (int) $image['id'];
        $image['entity_id'] = (int) $image['entity_id'];
        $image['size_bytes'] = (int) $image['size_bytes'];
        $image['width_px'] = (int) $image['width_px'];
        $image['height_px'] = (int) $image['height_px'];
        $image['url'] = '/media/assets/' . $image['id'];
        return $image;
    }

    private function appendSearchResult(array &$results, string $query, string $type, int $id, string $code, string $name, string $context, string $href): void
    {
        if (!str_contains(mb_strtolower($code . ' ' . $name . ' ' . $context), $query)) {
            return;
        }
        $results[] = compact('type', 'id', 'code', 'name', 'context', 'href');
    }

    private function demoInputEndpoint(array $input, string $side): array
    {
        $key = strtoupper(trim((string) ($input[$side . '_endpoint'] ?? '')));
        $legacyLocationId = (int) ($input[$side . '_location_id'] ?? 0);
        if ($key === '' && $legacyLocationId > 0) {
            $key = 'LOCATION:' . $legacyLocationId;
        }
        $endpoint = $this->demoEndpointByKey($key);
        if ($endpoint === null) {
            throw new \RuntimeException('Cable endpoint not found');
        }
        return $endpoint;
    }

    private function demoServerRoomContext(int $serverRoomId): ?array
    {
        foreach ($this->locations() as $location) {
            $detail = $this->location((int) $location['id']);
            foreach ($detail['rooms_detail'] ?? [] as $room) {
                if ((int) $room['id'] === $serverRoomId) {
                    return [
                        'location_id' => (int) $location['id'],
                        'location_name' => $location['name'],
                        'room_name' => $room['name'],
                    ];
                }
            }
        }
        return null;
    }

    private function demoCableEndpoint(array $cable, string $side): ?array
    {
        $key = strtoupper(trim((string) ($cable[$side . '_endpoint_key'] ?? '')));
        if ($key === '') {
            $locationId = (int) ($cable[$side . '_location_id'] ?? 0);
            $key = $locationId > 0 ? 'LOCATION:' . $locationId : '';
        }
        return $this->demoEndpointByKey($key);
    }

    private function demoEndpointByKey(string $key): ?array
    {
        foreach ($this->cableEndpointOptions() as $group) {
            foreach ($group as $endpoint) {
                if ($endpoint['key'] === $key) {
                    return $endpoint;
                }
            }
        }
        return null;
    }

    private function demoEndpointMatches(array $endpoint, array $context): bool
    {
        return (int) $endpoint['location_id'] === (int) $context['location_id']
            && ($endpoint['server_room_id'] === null || (int) $endpoint['server_room_id'] === (int) $context['server_room_id'])
            && ($endpoint['rack_id'] === null || (int) $endpoint['rack_id'] === (int) $context['rack_id']);
    }

    private function demoPanelContext(array $panel): ?array
    {
        $rack = $this->rack((int) $panel['rack_id']);
        if ($rack === null) {
            return null;
        }
        $locationId = (int) ($panel['location_id'] ?? $rack['location_id'] ?? 0);
        if ($locationId < 1) {
            foreach ($this->locations() as $location) {
                if ($location['name'] === $panel['location']) {
                    $locationId = (int) $location['id'];
                    break;
                }
            }
        }
        return [
            'location_id' => $locationId,
            'server_room_id' => (int) ($rack['server_room_id'] ?? 0),
            'rack_id' => (int) $panel['rack_id'],
        ];
    }

    private function demoPortContext(int $portId): ?array
    {
        foreach ($this->allPanels() as $panel) {
            foreach ($panel['port_items'] as $port) {
                if ((int) $port['id'] === $portId) {
                    return $this->demoPanelContext($panel);
                }
            }
        }
        return null;
    }

    private function findLocation(int $locationId): ?array
    {
        foreach ($this->locations() as $location) {
            if ((int) $location['id'] === $locationId) {
                return $location;
            }
        }
        return null;
    }

    private function demoPortLocationId(int $portId): ?int
    {
        foreach ($this->allPanels() as $panel) {
            foreach ($panel['port_items'] as $port) {
                if ((int) $port['id'] !== $portId) {
                    continue;
                }
                foreach ($this->locations() as $location) {
                    if ($location['name'] === $panel['location']) {
                        return (int) $location['id'];
                    }
                }
            }
        }
        return null;
    }

    private function allPanels(): array
    {
        $panels = [];
        foreach ([1, 2, 3, 10] as $id) {
            $panel = $this->panel($id);
            if ($panel !== null) {
                $panels[] = $panel;
            }
        }
        foreach ($_SESSION['demo_panels'] ?? [] as $rackPanels) {
            foreach ($rackPanels as $panel) {
                if (!in_array((int) $panel['id'], array_map('intval', $_SESSION['demo_archived_panels'] ?? []), true)) {
                    $panels[] = $panel;
                }
            }
        }
        return $panels;
    }

    private function findDemoPortLocation(int $portId): ?array
    {
        foreach ($_SESSION['demo_panels'] ?? [] as $rackId => $rackPanels) {
            foreach ($rackPanels as $panelIndex => $panel) {
                foreach ($panel['port_items'] as $portIndex => $port) {
                    if ((int) $port['id'] === $portId) {
                        return [$rackId, $panelIndex, $portIndex];
                    }
                }
            }
        }
        return null;
    }

    private function describeDemoPort(int $portId): string
    {
        foreach ($this->allPanels() as $panel) {
            foreach ($panel['port_items'] as $port) {
                if ((int) $port['id'] === $portId) {
                    return sprintf('%s · %s · %s · %s · Port %02d', $panel['location'], $panel['room'], $panel['rack'], $panel['code'], $port['number']);
                }
            }
        }
        return 'Remote port';
    }

    private function portDestination(int $panelId, int $number): string
    {
        return match ($panelId) {
            1 => $number <= 24
                ? sprintf('North Office · PP-NORTH-01 · %02d', $number)
                : sprintf('Research Campus · PP-LAB-01 · %02d', $number - 24),
            2 => sprintf('Warsaw Core · PP-WAW-01 · %02d', $number),
            default => sprintf('Warsaw Core · PP-WAW-01 · %02d', $number + 24),
        };
    }

    private function fiberColor(int $number): string
    {
        $colors = ['blue', 'orange', 'green', 'brown', 'slate', 'white', 'red', 'black', 'yellow', 'violet', 'rose', 'aqua'];

        return $colors[($number - 1) % count($colors)];
    }
}
