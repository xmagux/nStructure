<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

trait BuildsInventoryGraph
{
    public function inventory(): array
    {
        $nodes = [];
        $edges = [];
        $summary = ['locations' => 0, 'rooms' => 0, 'racks' => 0, 'panels' => 0];

        foreach ($this->locations() as $location) {
            $locationId = (int) $location['id'];
            $locationNodeId = 'inventory-location-' . $locationId;
            $summary['locations']++;
            $summary['rooms'] += (int) $location['rooms'];
            $summary['racks'] += (int) $location['racks'];
            $summary['panels'] += (int) $location['panels'];

            $nodes[] = [
                'id' => $locationNodeId,
                'entity_id' => $locationId,
                'type' => 'location',
                'code' => $location['code'],
                'name' => $location['name'],
                'icon_key' => $location['icon_key'] ?? 'loc-office',
                'subtitle' => sprintf('%d rooms · %d racks', $location['rooms'], $location['racks']),
                'rooms' => (int) $location['rooms'],
                'racks' => (int) $location['racks'],
                'status' => $location['status'],
                'href' => '/locations/' . $locationId,
            ];

            $detail = $this->location($locationId);
            foreach ($detail['rooms_detail'] ?? [] as $room) {
                $roomId = (int) $room['id'];
                $roomNodeId = 'inventory-room-' . $roomId;
                $nodes[] = [
                    'id' => $roomNodeId,
                    'entity_id' => $roomId,
                    'type' => 'room',
                    'code' => $room['code'],
                    'name' => $room['name'],
                    'subtitle' => sprintf('%d racks · floor %s', count($room['racks']), $room['floor'] ?: '—'),
                    'rack_count' => count($room['racks']),
                    'floor' => $room['floor'] ?: '—',
                    'status' => 'online',
                    'href' => '/locations/' . $locationId,
                ];
                $edges[] = [
                    'id' => sprintf('contains-location-%d-room-%d', $locationId, $roomId),
                    'from' => $locationNodeId,
                    'to' => $roomNodeId,
                    'type' => 'contains',
                ];

                foreach ($room['racks'] as $rack) {
                    $rackId = (int) $rack['id'];
                    $rackNodeId = 'inventory-rack-' . $rackId;
                    $rackDetail = $this->rack($rackId);
                    $nodes[] = [
                        'id' => $rackNodeId,
                        'entity_id' => $rackId,
                        'type' => 'rack',
                        'code' => $rack['code'],
                        'name' => $rack['name'],
                        'subtitle' => sprintf('%d/%dU · %d panels', $rack['units_used'], $rack['units_total'], $rack['panels']),
                        'units_used' => (int) $rack['units_used'],
                        'units_total' => (int) $rack['units_total'],
                        'panels' => (int) $rack['panels'],
                        'status' => 'online',
                        'href' => $rackDetail !== null ? '/racks/' . $rackId : '/locations/' . $locationId,
                    ];
                    $edges[] = [
                        'id' => sprintf('contains-room-%d-rack-%d', $roomId, $rackId),
                        'from' => $roomNodeId,
                        'to' => $rackNodeId,
                        'type' => 'contains',
                    ];

                    $knownPanels = 0;
                    foreach ($rackDetail['devices'] ?? [] as $device) {
                        if ($device['type'] !== 'patch_panel') {
                            continue;
                        }
                        $knownPanels++;
                        $panelId = (int) $device['id'];
                        $panelNodeId = 'inventory-panel-' . $panelId;
                        $nodes[] = [
                            'id' => $panelNodeId,
                            'entity_id' => $panelId,
                            'type' => 'panel',
                            'code' => $device['code'],
                            'name' => $device['name'],
                            'subtitle' => sprintf('%d ports · %d occupied', $device['ports'], $device['occupied']),
                            'ports' => (int) $device['ports'],
                            'occupied' => (int) $device['occupied'],
                            'status' => (int) $device['occupied'] < (int) $device['ports'] ? 'attention' : 'online',
                            'href' => '/patch-panels/' . $panelId,
                        ];
                        $edges[] = [
                            'id' => sprintf('contains-rack-%d-panel-%d', $rackId, $panelId),
                            'from' => $rackNodeId,
                            'to' => $panelNodeId,
                            'type' => 'contains',
                        ];
                    }

                    $unmodeledPanels = max(0, (int) $rack['panels'] - $knownPanels);
                    if ($unmodeledPanels > 0) {
                        $aggregateNodeId = 'inventory-panel-group-' . $rackId;
                        $nodes[] = [
                            'id' => $aggregateNodeId,
                            'entity_id' => null,
                            'type' => 'panel_group',
                            'code' => sprintf('%d× PANEL', $unmodeledPanels),
                            'name' => 'Documented panels',
                            'subtitle' => 'Open the rack for the physical view',
                            'panel_count' => $unmodeledPanels,
                            'status' => 'online',
                            'href' => $rackDetail !== null ? '/racks/' . $rackId : '/locations/' . $locationId,
                        ];
                        $edges[] = [
                            'id' => sprintf('contains-rack-%d-panel-group', $rackId),
                            'from' => $rackNodeId,
                            'to' => $aggregateNodeId,
                            'type' => 'contains',
                        ];
                    }
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'summary' => $summary];
    }
}
