<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Repository;

use NStructure\Domain\Exception\ResourceInUseException;
use NStructure\Domain\Repository\NetworkRepository;
use PDO;
use RuntimeException;

final readonly class MySqlNetworkRepository implements NetworkRepository
{
    use BuildsInventoryGraph;

    public function __construct(private PDO $pdo)
    {
    }

    public function dashboard(): array
    {
        $locations = $this->locations();
        $cables = $this->cables();
        $strandStats = $this->pdo->query(
            "SELECT COUNT(*) AS total,
                SUM(fs.operational_status = 'ACTIVE') AS active,
                SUM(fs.operational_status = 'RESERVED') AS reserved,
                SUM(fs.operational_status = 'DAMAGED') AS damaged,
                SUM(fs.operational_status = 'AVAILABLE') AS available
             FROM fiber_strands fs
             JOIN cable_segments cs ON cs.id = fs.cable_segment_id
             JOIN cables c ON c.id = cs.cable_id
             WHERE c.archived_at IS NULL",
        )->fetch() ?: [];
        $openEnds = (int) $this->pdo->query(
            'SELECT COUNT(*)
             FROM fiber_ends fe
             JOIN fiber_strands fs ON fs.id = fe.fiber_strand_id
             JOIN cable_segments cs ON cs.id = fs.cable_segment_id
             JOIN cables c ON c.id = cs.cable_id
             LEFT JOIN fiber_connection_ends fce ON fce.fiber_end_id = fe.id
             WHERE c.archived_at IS NULL AND fce.fiber_end_id IS NULL',
        )->fetchColumn();
        $total = max(1, (int) ($strandStats['total'] ?? 0));
        $healthy = $total - (int) ($strandStats['damaged'] ?? 0);

        return [
            'stats' => [
                ['key' => 'locations', 'value' => count($locations), 'trend' => 'live', 'tone' => 'violet'],
                ['key' => 'active_cables', 'value' => count(array_filter($cables, static fn (array $cable): bool => $cable['status'] === 'active')), 'trend' => 'live', 'tone' => 'cyan'],
                ['key' => 'fiber_capacity', 'value' => $total . 'J', 'trend' => round(((int) ($strandStats['active'] ?? 0) / $total) * 100, 1) . '%', 'tone' => 'blue'],
                ['key' => 'open_ends', 'value' => $openEnds, 'trend' => 'live', 'tone' => 'amber'],
            ],
            'health' => [
                'score' => round(($healthy / $total) * 100, 1),
                'active' => (int) ($strandStats['active'] ?? 0),
                'reserved' => (int) ($strandStats['reserved'] ?? 0),
                'damaged' => (int) ($strandStats['damaged'] ?? 0),
                'available' => (int) ($strandStats['available'] ?? 0),
            ],
            'locations' => $locations,
            'cables' => $cables,
            'alerts' => $this->alerts($openEnds, (int) ($strandStats['damaged'] ?? 0)),
            'activity' => $this->recentActivity(),
            'topology' => $this->topology(),
        ];
    }

    public function topology(): array
    {
        $locationRows = $this->pdo->query(
            'SELECT l.id, l.code, l.name, l.icon_key,
                COUNT(DISTINCT sr.id) AS rooms,
                COUNT(DISTINCT r.id) AS racks
             FROM locations l
             LEFT JOIN server_rooms sr ON sr.location_id = l.id AND sr.archived_at IS NULL
             LEFT JOIN racks r ON r.server_room_id = sr.id AND r.archived_at IS NULL
             WHERE l.archived_at IS NULL
             GROUP BY l.id, l.code, l.name
             ORDER BY l.id',
        )->fetchAll();
        $closureRows = $this->pdo->query(
            "SELECT fn.id, fn.code, fn.name, sc.tray_count,
                COUNT(fc.id) AS splices
             FROM fiber_nodes fn
             JOIN splice_closures sc ON sc.fiber_node_id = fn.id
             LEFT JOIN fiber_connections fc ON fc.fiber_node_id = fn.id AND fc.connection_type = 'SPLICE'
             WHERE fn.archived_at IS NULL
             GROUP BY fn.id, fn.code, fn.name, sc.tray_count",
        )->fetchAll();

        $nodes = [];
        $locationCount = max(1, count($locationRows));
        foreach ($locationRows as $index => $row) {
            $nodes[] = [
                'id' => 'loc-' . $row['id'],
                'entity_id' => (int) $row['id'],
                'type' => 'location',
                'code' => $row['code'],
                'name' => $row['name'],
                'icon_key' => $row['icon_key'],
                'subtitle' => sprintf('%d rooms · %d racks', $row['rooms'], $row['racks']),
                'x' => 14 + (($index % 2) * 68),
                'y' => 20 + (floor($index / 2) * 48),
                'status' => 'online',
            ];
        }
        foreach ($closureRows as $index => $row) {
            $nodes[] = [
                'id' => 'node-' . $row['id'],
                'entity_id' => (int) $row['id'],
                'type' => 'splice',
                'code' => $row['code'],
                'name' => $row['name'],
                'subtitle' => sprintf('%d active splices', $row['splices']),
                'x' => 48,
                'y' => 42 + ($index * 16),
                'status' => 'online',
            ];
        }

        $segmentRows = $this->pdo->query(
            'SELECT cs.id, cs.segment_code, cs.fiber_count, cs.length_m,
                c.code AS cable_code, c.medium_type,
                an.id AS a_node_id, an.location_id AS a_location_id,
                zn.id AS z_node_id, zn.location_id AS z_location_id,
                COUNT(DISTINCT CASE WHEN fs.operational_status = \'ACTIVE\' THEN fs.id END) AS used
             FROM cable_segments cs
             JOIN cables c ON c.id = cs.cable_id
             JOIN fiber_nodes an ON an.id = cs.a_node_id
             JOIN fiber_nodes zn ON zn.id = cs.z_node_id
             LEFT JOIN fiber_strands fs ON fs.cable_segment_id = cs.id
             WHERE c.archived_at IS NULL AND an.archived_at IS NULL AND zn.archived_at IS NULL
             GROUP BY cs.id, cs.segment_code, cs.fiber_count, cs.length_m, c.code, c.medium_type,
                an.id, an.location_id, zn.id, zn.location_id
             ORDER BY cs.id',
        )->fetchAll();
        $tones = ['violet', 'cyan', 'amber', 'blue'];
        $edges = [];
        foreach ($segmentRows as $index => $row) {
            $edges[] = [
                'id' => 'seg-' . $row['id'],
                'from' => $row['a_location_id'] ? 'loc-' . $row['a_location_id'] : 'node-' . $row['a_node_id'],
                'to' => $row['z_location_id'] ? 'loc-' . $row['z_location_id'] : 'node-' . $row['z_node_id'],
                'code' => $row['segment_code'],
                'cable' => $row['cable_code'],
                'medium' => $row['medium_type'],
                'fibers' => (int) $row['fiber_count'],
                'used' => (int) $row['used'],
                'length' => number_format(((float) $row['length_m']) / 1000, 2) . ' km',
                'tone' => $tones[$index % count($tones)],
            ];
        }

        $totalLength = array_sum(array_map(static fn (array $row): float => (float) $row['length_m'], $segmentRows));

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'summary' => [
                'locations' => $locationCount,
                'closures' => count($closureRows),
                'segments' => count($segmentRows),
                'total_length' => number_format($totalLength / 1000, 2) . ' km',
            ],
        ];
    }

    public function locations(): array
    {
        $rows = $this->pdo->query(
            'SELECT l.id, l.code, l.name, l.icon_key, l.address,
                COUNT(DISTINCT sr.id) AS rooms,
                COUNT(DISTINCT r.id) AS racks,
                COUNT(DISTINCT pp.id) AS panels,
                COALESCE(SUM(DISTINCT pp.port_count), 0) AS fibers,
                COALESCE(ROUND(AVG(ppu.utilization_percent)), 0) AS utilization
             FROM locations l
             LEFT JOIN server_rooms sr ON sr.location_id = l.id AND sr.archived_at IS NULL
             LEFT JOIN racks r ON r.server_room_id = sr.id AND r.archived_at IS NULL
             LEFT JOIN patch_panels pp ON pp.rack_id = r.id AND pp.archived_at IS NULL
             LEFT JOIN patch_panel_utilization ppu ON ppu.patch_panel_id = pp.id
             WHERE l.archived_at IS NULL
             GROUP BY l.id, l.code, l.name, l.address
             ORDER BY l.name',
        )->fetchAll();
        $accents = ['violet', 'cyan', 'amber', 'blue'];

        return array_map(static function (array $row, int $index) use ($accents): array {
            $row['id'] = (int) $row['id'];
            $row['rooms'] = (int) $row['rooms'];
            $row['racks'] = (int) $row['racks'];
            $row['panels'] = (int) $row['panels'];
            $row['fibers'] = (int) $row['fibers'];
            $row['utilization'] = (int) $row['utilization'];
            $row['status'] = $row['utilization'] > 90 ? 'attention' : 'healthy';
            $row['accent'] = $accents[$index % count($accents)];
            return $row;
        }, $rows, array_keys($rows));
    }

    public function location(int $id): ?array
    {
        $location = $this->findLocation($id);
        if ($location === null) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT sr.id, sr.code, sr.name, sr.floor
             FROM server_rooms sr
             WHERE sr.location_id = :location_id AND sr.archived_at IS NULL
             ORDER BY sr.name',
        );
        $statement->execute(['location_id' => $id]);
        $rooms = $statement->fetchAll();

        $rackStatement = $this->pdo->prepare(
            'SELECT r.id, r.server_room_id, r.code, r.name, r.total_units,
                COALESCE(SUM(pp.rack_unit_height), 0) AS units_used,
                COUNT(pp.id) AS panels
             FROM racks r
             LEFT JOIN patch_panels pp ON pp.rack_id = r.id AND pp.archived_at IS NULL
             WHERE r.server_room_id = :room_id AND r.archived_at IS NULL
             GROUP BY r.id, r.server_room_id, r.code, r.name, r.total_units
             ORDER BY r.position_index, r.code',
        );
        $upsStatement = $this->pdo->prepare(
            'SELECT id, server_room_id, code, name, manufacturer, model, serial_number,
                rated_power_va, rated_power_w, ip_address, management_url, battery_replaced_at,
                battery_replacement_interval_months, battery_count, battery_type,
                operational_status, notes, created_at, updated_at
             FROM ups_devices
             WHERE server_room_id = :room_id AND archived_at IS NULL
             ORDER BY name, code',
        );
        foreach ($rooms as &$room) {
            $rackStatement->execute(['room_id' => $room['id']]);
            $room['racks'] = array_map(static fn (array $rack): array => [
                'id' => (int) $rack['id'],
                'code' => $rack['code'],
                'name' => $rack['name'],
                'units_used' => (int) $rack['units_used'],
                'units_total' => (int) $rack['total_units'],
                'panels' => (int) $rack['panels'],
            ], $rackStatement->fetchAll());
            $upsStatement->execute(['room_id' => $room['id']]);
            $room['ups_devices'] = array_map(
                fn (array $upsDevice): array => $this->normalizeUpsDevice($upsDevice),
                $upsStatement->fetchAll(),
            );
            $room['temperature'] = '—';
            $room['images'] = $this->assetImages('SERVER_ROOM', (int) $room['id']);
        }
        unset($room);
        $location['rooms_detail'] = $rooms;
        $location['images'] = $this->assetImages('LOCATION', $id);

        return $location;
    }

    public function rack(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.server_room_id, r.code, r.name, r.total_units, r.row_label,
                sr.name AS room, l.id AS location_id, l.name AS location
             FROM racks r
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE r.id = :id AND r.archived_at IS NULL',
        );
        $statement->execute(['id' => $id]);
        $rack = $statement->fetch();
        if (!is_array($rack)) {
            return null;
        }

        $panels = $this->pdo->prepare(
            'SELECT pp.id, pp.code, pp.name, pp.rack_unit_start AS start, pp.rack_unit_height AS height,
                pp.port_count AS ports, pp.layout_rows AS port_rows, COALESCE(ppu.occupied_ports, 0) AS occupied
             FROM patch_panels pp
             LEFT JOIN patch_panel_utilization ppu ON ppu.patch_panel_id = pp.id
             WHERE pp.rack_id = :rack_id AND pp.archived_at IS NULL
             ORDER BY pp.rack_unit_start DESC',
        );
        $panels->execute(['rack_id' => $id]);
        $portStatement = $this->pdo->prepare(
            'SELECT ppp.patch_panel_id, ppp.port_number AS number, ppp.label, ppp.highlight_color, ppp.remote_endpoint_label,
                CASE
                    WHEN ppp.administrative_status <> "AVAILABLE" THEN LOWER(ppp.administrative_status)
                    WHEN EXISTS (
                        SELECT 1 FROM fiber_connection_ports fcp WHERE fcp.patch_panel_port_id = ppp.id
                    ) OR EXISTS (
                        SELECT 1 FROM patch_cord_connections pc
                        WHERE pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                          AND (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                    ) OR EXISTS (
                        SELECT 1
                        FROM rear_fiber_connection_ports rfcp
                        JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
                        WHERE rfcp.patch_panel_port_id = ppp.id
                          AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                    ) OR EXISTS (
                        SELECT 1 FROM patch_panel_front_connections pfc WHERE pfc.patch_panel_port_id = ppp.id
                    ) OR EXISTS (
                        SELECT 1
                        FROM front_panel_connection_ports fpcp
                        JOIN front_panel_connections front_link ON front_link.id = fpcp.front_panel_connection_id
                        WHERE fpcp.patch_panel_port_id = ppp.id
                          AND front_link.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                    ) THEN "occupied"
                    ELSE "available"
                END AS status,
                COALESCE(
                    (SELECT CONCAT(rl.name, " · ", rsr.name, " · ", rr.code, " · ", rpp.code, " · Port ", LPAD(rp.port_number, 2, "0"),
                        " · via ", (SELECT GROUP_CONCAT(c.code ORDER BY rfcs.sequence_index SEPARATOR " → ")
                            FROM rear_fiber_connection_segments rfcs
                            JOIN cable_segments cs ON cs.id = rfcs.cable_segment_id
                            JOIN cables c ON c.id = cs.cable_id
                            WHERE rfcs.rear_fiber_connection_id = rfc.id))
                     FROM rear_fiber_connection_ports own_rfcp
                     JOIN rear_fiber_connections rfc ON rfc.id = own_rfcp.rear_fiber_connection_id
                     JOIN rear_fiber_connection_ports remote_rfcp ON remote_rfcp.rear_fiber_connection_id = rfc.id AND remote_rfcp.endpoint_side <> own_rfcp.endpoint_side
                     JOIN patch_panel_ports rp ON rp.id = remote_rfcp.patch_panel_port_id
                     JOIN patch_panels rpp ON rpp.id = rp.patch_panel_id
                     JOIN racks rr ON rr.id = rpp.rack_id
                     JOIN server_rooms rsr ON rsr.id = rr.server_room_id
                     JOIN locations rl ON rl.id = rsr.location_id
                     WHERE own_rfcp.patch_panel_port_id = ppp.id
                       AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                     ORDER BY rfc.id DESC LIMIT 1),
                    (SELECT CONCAT(rl.name, " · ", rsr.name, " · ", rr.code, " · ", rpp.code, " · Port ", LPAD(rp.port_number, 2, "0"))
                     FROM patch_cord_connections pc
                     JOIN patch_panel_ports rp ON rp.id = IF(pc.a_port_id = ppp.id, pc.z_port_id, pc.a_port_id)
                     JOIN patch_panels rpp ON rpp.id = rp.patch_panel_id
                     JOIN racks rr ON rr.id = rpp.rack_id
                     JOIN server_rooms rsr ON rsr.id = rr.server_room_id
                     JOIN locations rl ON rl.id = rsr.location_id
                     WHERE pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                       AND (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                     ORDER BY pc.id DESC LIMIT 1)
                ) AS patch_destination,
                (SELECT CONCAT(c.code, " · ", cs.segment_code, " · Fiber ", LPAD(fs.strand_number, 2, "0"))
                 FROM fiber_connection_ports fcp
                 JOIN fiber_connections fc ON fc.id = fcp.connection_id
                 JOIN fiber_connection_ends fce ON fce.connection_id = fc.id
                 JOIN fiber_ends fe ON fe.id = fce.fiber_end_id
                 JOIN fiber_strands fs ON fs.id = fe.fiber_strand_id
                 JOIN cable_segments cs ON cs.id = fs.cable_segment_id
                 JOIN cables c ON c.id = cs.cable_id
                 WHERE fcp.patch_panel_port_id = ppp.id
                 ORDER BY fc.id DESC LIMIT 1) AS fiber_destination,
                COALESCE(
                    (SELECT CONCAT(adl.name, " · ", adsr.name, " · ", adr.code, " · ", ad.vendor, " ", ad.name,
                        IF(ad.model IS NULL, "", CONCAT(" (", ad.model, ")")), " · ", adi.name)
                     FROM patch_panel_front_connections pfc
                     JOIN active_device_interfaces adi ON adi.id = pfc.active_device_interface_id
                     JOIN active_devices ad ON ad.id = adi.active_device_id
                     JOIN racks adr ON adr.id = ad.rack_id
                     JOIN server_rooms adsr ON adsr.id = adr.server_room_id
                     JOIN locations adl ON adl.id = adsr.location_id
                     WHERE pfc.patch_panel_port_id = ppp.id
                     LIMIT 1),
                    (SELECT CONCAT(fl.name, " · ", fsr.name, " · ", fr.code, " · ", fpp.code, " · Port ", LPAD(fp.port_number, 2, "0"))
                     FROM front_panel_connection_ports own_front
                     JOIN front_panel_connections front_link ON front_link.id = own_front.front_panel_connection_id
                     JOIN front_panel_connection_ports remote_front
                        ON remote_front.front_panel_connection_id = front_link.id AND remote_front.endpoint_side <> own_front.endpoint_side
                     JOIN patch_panel_ports fp ON fp.id = remote_front.patch_panel_port_id
                     JOIN patch_panels fpp ON fpp.id = fp.patch_panel_id
                     JOIN racks fr ON fr.id = fpp.rack_id
                     JOIN server_rooms fsr ON fsr.id = fr.server_room_id
                     JOIN locations fl ON fl.id = fsr.location_id
                     WHERE own_front.patch_panel_port_id = ppp.id
                       AND front_link.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                     LIMIT 1)
                ) AS front_destination
             FROM patch_panel_ports ppp
             JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
             WHERE pp.rack_id = :rack_id AND pp.archived_at IS NULL
             ORDER BY ppp.patch_panel_id, ppp.port_number',
        );
        $portStatement->execute(['rack_id' => $id]);
        $portsByPanel = [];
        foreach ($portStatement->fetchAll() as $port) {
            $rearDestination = $port['patch_destination'] ?: ($port['remote_endpoint_label'] ?: $port['fiber_destination']);
            $frontDestination = $port['front_destination'];
            $portsByPanel[(int) $port['patch_panel_id']][] = [
                'number' => (int) $port['number'],
                'status' => $port['status'],
                'label' => $port['label'],
                'highlight_color' => $port['highlight_color'],
                'destination' => $frontDestination ?: $rearDestination,
                'rear_destination' => $rearDestination,
                'front_destination' => $frontDestination,
            ];
        }
        $devices = array_map(static function (array $panel) use ($portsByPanel): array {
            $panelId = (int) $panel['id'];
            return [
                'id' => $panelId,
                'code' => $panel['code'],
                'name' => $panel['name'],
                'start' => (int) $panel['start'],
                'height' => (int) $panel['height'],
                'ports' => (int) $panel['ports'],
                'rows' => (int) $panel['port_rows'],
                'occupied' => (int) $panel['occupied'],
                'port_items' => $portsByPanel[$panelId] ?? [],
                'type' => 'patch_panel',
                'tone' => 'violet',
            ];
        }, $panels->fetchAll());

        $rackItemsStatement = $this->pdo->prepare(
            'SELECT id, name, kind, rack_unit_start AS start, rack_unit_height AS height, notes
             FROM rack_items WHERE rack_id = :rack_id AND archived_at IS NULL
             ORDER BY rack_unit_start DESC',
        );
        $rackItemsStatement->execute(['rack_id' => $id]);
        $rackItemTones = [
            'ORGANIZER' => 'slate', 'PATCH_PANEL' => 'violet', 'FREE_SPACE' => 'slate',
            'POWER' => 'amber', 'ACTIVE_DEVICE' => 'cyan', 'UPS' => 'amber', 'OTHER' => 'slate',
        ];
        $rackItems = array_map(static function (array $item) use ($rackItemTones): array {
            $kind = (string) $item['kind'];
            return [
                'id' => (int) $item['id'],
                'code' => $kind,
                'name' => $item['name'],
                'kind' => $kind,
                'notes' => $item['notes'],
                'start' => (int) $item['start'],
                'height' => (int) $item['height'],
                'type' => 'rack_item',
                'tone' => $rackItemTones[$kind] ?? 'slate',
            ];
        }, $rackItemsStatement->fetchAll());
        $devices = [...$devices, ...$rackItems];

        $activeDevicesStatement = $this->pdo->prepare(
            'SELECT ad.id, ad.code, ad.name, ad.device_type, ad.vendor, ad.model, ad.management_address, ad.notes,
                (SELECT COUNT(*) FROM active_device_interfaces WHERE active_device_id = ad.id) AS interface_count,
                (SELECT COUNT(*) FROM active_device_interfaces adi
                 JOIN patch_panel_front_connections pfc ON pfc.active_device_interface_id = adi.id
                 WHERE adi.active_device_id = ad.id) AS connected_count
             FROM active_devices ad
             WHERE ad.rack_id = :rack_id AND ad.archived_at IS NULL
             ORDER BY ad.name',
        );
        $activeDevicesStatement->execute(['rack_id' => $id]);
        $activeDevices = array_map(static fn (array $device): array => [
            'id' => (int) $device['id'],
            'code' => $device['code'],
            'name' => $device['name'],
            'device_type' => $device['device_type'],
            'vendor' => $device['vendor'],
            'model' => $device['model'],
            'management_address' => $device['management_address'],
            'notes' => $device['notes'],
            'interface_count' => (int) $device['interface_count'],
            'connected_count' => (int) $device['connected_count'],
        ], $activeDevicesStatement->fetchAll());

        $used = array_sum(array_map(static fn (array $device): int => (int) $device['height'], $devices));

        return [
            'id' => (int) $rack['id'],
            'server_room_id' => (int) $rack['server_room_id'],
            'location_id' => (int) $rack['location_id'],
            'code' => $rack['code'],
            'name' => $rack['name'],
            'row_label' => $rack['row_label'],
            'room' => $rack['room'],
            'location' => $rack['location'],
            'total_units' => (int) $rack['total_units'],
            'power' => '—',
            'temperature' => '—',
            'utilization' => round(($used / max(1, (int) $rack['total_units'])) * 100),
            'devices' => $devices,
            'active_devices' => $activeDevices,
            'images' => $this->assetImages('RACK', $id),
        ];
    }

    public function panel(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT pp.id, pp.rack_id, pp.fiber_node_id, pp.code, pp.name, pp.port_count AS ports,
                pp.rack_unit_start, pp.rack_unit_height, pp.layout_rows, pp.layout_columns,
                pp.manufacturer, pp.model,
                COALESCE(ppu.occupied_ports, 0) AS occupied,
                COALESCE(ppu.available_ports, pp.port_count) AS available,
                COALESCE(ppu.utilization_percent, 0) AS utilization,
                (SELECT COUNT(DISTINCT terminated_port.id)
                 FROM patch_panel_ports terminated_port
                 LEFT JOIN fiber_connection_ports termination ON termination.patch_panel_port_id = terminated_port.id
                 LEFT JOIN rear_fiber_connection_ports rear_endpoint ON rear_endpoint.patch_panel_port_id = terminated_port.id
                 LEFT JOIN rear_fiber_connections rear_connection
                    ON rear_connection.id = rear_endpoint.rear_fiber_connection_id
                    AND rear_connection.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                 WHERE terminated_port.patch_panel_id = pp.id
                   AND (termination.patch_panel_port_id IS NOT NULL OR rear_connection.id IS NOT NULL)) AS terminated_ports,
                r.code AS rack, sr.name AS room, l.id AS location_id, l.name AS location,
                GROUP_CONCAT(DISTINCT ct.code ORDER BY ct.code SEPARATOR ", ") AS connector,
                MIN(ct.id) AS connector_type_id
             FROM patch_panels pp
             JOIN racks r ON r.id = pp.rack_id
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             LEFT JOIN patch_panel_utilization ppu ON ppu.patch_panel_id = pp.id
             LEFT JOIN patch_panel_ports ppp ON ppp.patch_panel_id = pp.id
             LEFT JOIN connector_types ct ON ct.id = ppp.connector_type_id
             WHERE pp.id = :id AND pp.archived_at IS NULL
             GROUP BY pp.id, pp.rack_id, pp.fiber_node_id, pp.code, pp.name, pp.port_count, pp.rack_unit_start,
                pp.rack_unit_height, pp.layout_rows, pp.layout_columns, pp.manufacturer, pp.model, ppu.occupied_ports,
                ppu.available_ports, ppu.utilization_percent, r.code, sr.name, l.id, l.name',
        );
        $statement->execute(['id' => $id]);
        $panel = $statement->fetch();
        if (!is_array($panel)) {
            return null;
        }

        $portStatement = $this->pdo->prepare(
            'SELECT ppp.id, ppp.port_number AS number, ppp.label, ppp.highlight_color, ppp.remote_endpoint_label, ppp.notes, ppp.administrative_status,
                ct.id AS connector_type_id, ct.code AS connector, fs.strand_number, fs.strand_color,
                cs.segment_code, c.code AS cable_code,
                pfc.id AS front_connection_id, pfc.patch_cord_label AS front_patch_cord_label, pfc.notes AS front_connection_notes,
                adi.id AS active_interface_id, adi.name AS active_interface_name, adi.interface_type AS active_interface_type,
                adi.speed_label AS active_interface_speed, ad.id AS active_device_id, ad.rack_id AS active_device_rack_id,
                ad.code AS active_device_code, ad.name AS active_device_name, ad.device_type AS active_device_type,
                ad.vendor AS active_device_vendor, ad.model AS active_device_model,
                adr.code AS active_device_rack, adsr.name AS active_device_room, adl.name AS active_device_location,
                front_link.id AS front_port_connection_id, front_link.code AS front_port_connection_code,
                front_link.patch_cord_label AS front_port_patch_cord_label, front_link.notes AS front_port_connection_notes,
                remote_front.patch_panel_port_id AS front_destination_port_id, front_port.port_number AS front_destination_port_number,
                front_panel.id AS front_destination_panel_id, front_panel.code AS front_destination_panel_code,
                front_rack.id AS front_destination_rack_id, front_rack.code AS front_destination_rack,
                front_room.name AS front_destination_room, front_location.name AS front_destination_location,
                CASE
                    WHEN fcp.connection_id IS NOT NULL OR pfc.id IS NOT NULL OR EXISTS (
                        SELECT 1 FROM patch_cord_connections pc
                        WHERE pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                          AND (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                    ) OR EXISTS (
                        SELECT 1
                        FROM rear_fiber_connection_ports rfcp
                        JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
                        WHERE rfcp.patch_panel_port_id = ppp.id
                          AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                    ) OR front_link.id IS NOT NULL THEN "occupied"
                    ELSE "available"
                END AS status,
                fc.measured_loss_db,
                COALESCE(
                    (SELECT rfc.code
                     FROM rear_fiber_connection_ports rfcp
                     JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
                     WHERE rfcp.patch_panel_port_id = ppp.id
                       AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                     ORDER BY rfc.id DESC LIMIT 1),
                    (SELECT pc.code
                     FROM patch_cord_connections pc
                     WHERE pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                       AND (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                     ORDER BY pc.id DESC LIMIT 1)
                ) AS patch_cord_code,
                COALESCE(
                    (SELECT CONCAT(rl.name, " · ", rsr.name, " · ", rr.code, " · ", rpp.code, " · Port ", LPAD(rp.port_number, 2, "0"),
                        " · via ", (SELECT GROUP_CONCAT(c.code ORDER BY rfcs.sequence_index SEPARATOR " → ")
                            FROM rear_fiber_connection_segments rfcs
                            JOIN cable_segments cs ON cs.id = rfcs.cable_segment_id
                            JOIN cables c ON c.id = cs.cable_id
                            WHERE rfcs.rear_fiber_connection_id = rfc.id))
                     FROM rear_fiber_connection_ports own_rfcp
                     JOIN rear_fiber_connections rfc ON rfc.id = own_rfcp.rear_fiber_connection_id
                     JOIN rear_fiber_connection_ports remote_rfcp ON remote_rfcp.rear_fiber_connection_id = rfc.id AND remote_rfcp.endpoint_side <> own_rfcp.endpoint_side
                     JOIN patch_panel_ports rp ON rp.id = remote_rfcp.patch_panel_port_id
                     JOIN patch_panels rpp ON rpp.id = rp.patch_panel_id
                     JOIN racks rr ON rr.id = rpp.rack_id
                     JOIN server_rooms rsr ON rsr.id = rr.server_room_id
                     JOIN locations rl ON rl.id = rsr.location_id
                     WHERE own_rfcp.patch_panel_port_id = ppp.id
                       AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                     ORDER BY rfc.id DESC LIMIT 1),
                    (SELECT CONCAT(rl.name, " · ", rsr.name, " · ", rr.code, " · ", rpp.code, " · Port ", LPAD(rp.port_number, 2, "0"))
                     FROM patch_cord_connections pc
                     JOIN patch_panel_ports rp ON rp.id = IF(pc.a_port_id = ppp.id, pc.z_port_id, pc.a_port_id)
                     JOIN patch_panels rpp ON rpp.id = rp.patch_panel_id
                     JOIN racks rr ON rr.id = rpp.rack_id
                     JOIN server_rooms rsr ON rsr.id = rr.server_room_id
                     JOIN locations rl ON rl.id = rsr.location_id
                     WHERE pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                       AND (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                     ORDER BY pc.id DESC LIMIT 1)
                ) AS patch_destination,
                (SELECT GROUP_CONCAT(CONCAT(cs.segment_code, " / ", LPAD(fs.strand_number, 2, "0")) ORDER BY rfcs.sequence_index SEPARATOR " → ")
                 FROM rear_fiber_connection_ports rfcp
                 JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
                 JOIN rear_fiber_connection_segments rfcs ON rfcs.rear_fiber_connection_id = rfc.id
                 JOIN cable_segments cs ON cs.id = rfcs.cable_segment_id
                 JOIN fiber_strands fs ON fs.id = rfcs.fiber_strand_id
                 WHERE rfcp.patch_panel_port_id = ppp.id
                   AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")) AS rear_fiber_path
             FROM patch_panel_ports ppp
             JOIN connector_types ct ON ct.id = ppp.connector_type_id
             LEFT JOIN fiber_connection_ports fcp ON fcp.patch_panel_port_id = ppp.id
             LEFT JOIN fiber_connections fc ON fc.id = fcp.connection_id
             LEFT JOIN fiber_connection_ends fce ON fce.connection_id = fc.id
             LEFT JOIN fiber_ends fe ON fe.id = fce.fiber_end_id
             LEFT JOIN fiber_strands fs ON fs.id = fe.fiber_strand_id
             LEFT JOIN cable_segments cs ON cs.id = fs.cable_segment_id
             LEFT JOIN cables c ON c.id = cs.cable_id
             LEFT JOIN patch_panel_front_connections pfc ON pfc.patch_panel_port_id = ppp.id
             LEFT JOIN active_device_interfaces adi ON adi.id = pfc.active_device_interface_id
             LEFT JOIN active_devices ad ON ad.id = adi.active_device_id
             LEFT JOIN racks adr ON adr.id = ad.rack_id
             LEFT JOIN server_rooms adsr ON adsr.id = adr.server_room_id
             LEFT JOIN locations adl ON adl.id = adsr.location_id
             LEFT JOIN front_panel_connection_ports own_front ON own_front.patch_panel_port_id = ppp.id
             LEFT JOIN front_panel_connections front_link
                ON front_link.id = own_front.front_panel_connection_id
                AND front_link.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
             LEFT JOIN front_panel_connection_ports remote_front
                ON remote_front.front_panel_connection_id = front_link.id AND remote_front.endpoint_side <> own_front.endpoint_side
             LEFT JOIN patch_panel_ports front_port ON front_port.id = remote_front.patch_panel_port_id
             LEFT JOIN patch_panels front_panel ON front_panel.id = front_port.patch_panel_id
             LEFT JOIN racks front_rack ON front_rack.id = front_panel.rack_id
             LEFT JOIN server_rooms front_room ON front_room.id = front_rack.server_room_id
             LEFT JOIN locations front_location ON front_location.id = front_room.location_id
             WHERE ppp.patch_panel_id = :panel_id
             ORDER BY ppp.port_number',
        );
        $portStatement->execute(['panel_id' => $id]);
        $ports = array_map(static function (array $port): array {
            $status = $port['administrative_status'] === 'AVAILABLE'
                ? $port['status']
                : strtolower($port['administrative_status']);
            $rearDestination = $port['patch_destination'] ?: ($port['remote_endpoint_label'] ?: ($port['segment_code'] ? 'Fiber termination · trace available' : null));
            $frontDestination = null;
            $frontConnection = null;
            if ($port['front_connection_id'] !== null) {
                $deviceLabel = trim((string) $port['active_device_vendor'] . ' ' . (string) $port['active_device_name']);
                if ($port['active_device_model']) {
                    $deviceLabel .= ' (' . $port['active_device_model'] . ')';
                }
                $frontDestination = implode(' · ', array_filter([
                    $port['active_device_location'],
                    $port['active_device_room'],
                    $port['active_device_rack'],
                    $deviceLabel,
                    $port['active_interface_name'],
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
                $frontConnection = [
                    'id' => (int) $port['front_connection_id'],
                    'device_id' => (int) $port['active_device_id'],
                    'device_rack_id' => (int) $port['active_device_rack_id'],
                    'device_code' => $port['active_device_code'],
                    'device_name' => $port['active_device_name'],
                    'device_type' => $port['active_device_type'],
                    'device_vendor' => $port['active_device_vendor'],
                    'device_model' => $port['active_device_model'],
                    'interface_id' => (int) $port['active_interface_id'],
                    'interface_name' => $port['active_interface_name'],
                    'interface_type' => $port['active_interface_type'],
                    'interface_speed' => $port['active_interface_speed'],
                    'patch_cord_label' => $port['front_patch_cord_label'],
                    'notes' => $port['front_connection_notes'],
                    'label' => $frontDestination,
                    'type' => 'DEVICE',
                ];
            } elseif ($port['front_port_connection_id'] !== null) {
                $frontDestination = implode(' · ', array_filter([
                    $port['front_destination_location'],
                    $port['front_destination_room'],
                    $port['front_destination_rack'],
                    $port['front_destination_panel_code'],
                    'Port ' . str_pad((string) $port['front_destination_port_number'], 2, '0', STR_PAD_LEFT),
                ]));
                $frontConnection = [
                    'id' => (int) $port['front_port_connection_id'],
                    'type' => 'PORT',
                    'destination_port_id' => (int) $port['front_destination_port_id'],
                    'destination_panel_id' => (int) $port['front_destination_panel_id'],
                    'destination_rack_id' => (int) $port['front_destination_rack_id'],
                    'patch_cord_label' => $port['front_port_patch_cord_label'],
                    'notes' => $port['front_port_connection_notes'],
                    'label' => $frontDestination,
                ];
            }
            return [
                'id' => (int) $port['id'],
                'number' => (int) $port['number'],
                'status' => $status,
                'administrative_status' => $port['administrative_status'],
                'connector_type_id' => (int) $port['connector_type_id'],
                'connector' => $port['connector'],
                'label' => $port['label'],
                'highlight_color' => $port['highlight_color'],
                'manual_remote_endpoint' => $port['remote_endpoint_label'],
                'notes' => $port['notes'],
                'fiber' => $port['rear_fiber_path'] ?: ($port['segment_code'] ? sprintf('%s / %02d', $port['segment_code'], $port['strand_number']) : null),
                'destination' => $frontDestination ?: $rearDestination,
                'rear_destination' => $rearDestination,
                'front_destination' => $frontDestination,
                'front_connection' => $frontConnection,
                'connection_code' => $port['patch_cord_code'],
                'has_termination' => $port['segment_code'] !== null,
                'has_patch_cord' => $port['patch_cord_code'] !== null,
                'has_front_connection' => $frontConnection !== null,
                'loss' => $port['measured_loss_db'] !== null ? number_format((float) $port['measured_loss_db'], 2) . ' dB' : null,
                'color' => strtolower((string) ($port['strand_color'] ?? 'slate')),
            ];
        }, $portStatement->fetchAll());

        $incoming = $this->pdo->prepare(
            'SELECT CONCAT(c.code, " · ", cs.fiber_count, "J ", c.medium_type) AS label, cs.fiber_count
             FROM patch_panels pp
             JOIN fiber_nodes fn ON fn.id = pp.fiber_node_id
             JOIN cable_segments cs ON cs.a_node_id = fn.id OR cs.z_node_id = fn.id
             JOIN cables c ON c.id = cs.cable_id
             WHERE pp.id = :panel_id
             ORDER BY cs.id LIMIT 1',
        );
        $incoming->execute(['panel_id' => $id]);
        $incomingRow = $incoming->fetch();

        $panel['id'] = (int) $panel['id'];
        $panel['rack_id'] = (int) $panel['rack_id'];
        $panel['location_id'] = (int) $panel['location_id'];
        $panel['fiber_node_id'] = (int) $panel['fiber_node_id'];
        $panel['ports'] = (int) $panel['ports'];
        $panel['rack_unit_start'] = (int) $panel['rack_unit_start'];
        $panel['rack_unit_height'] = (int) $panel['rack_unit_height'];
        $panel['layout_rows'] = (int) $panel['layout_rows'];
        $panel['layout_columns'] = (int) $panel['layout_columns'];
        $panel['connector_type_id'] = (int) $panel['connector_type_id'];
        $panel['occupied'] = (int) $panel['occupied'];
        $panel['available'] = (int) $panel['available'];
        $panel['utilization'] = (float) $panel['utilization'];
        $panel['terminated_ports'] = (int) $panel['terminated_ports'];
        $panel['incoming_capacity'] = is_array($incomingRow) ? (int) $incomingRow['fiber_count'] : 0;
        $panel['unterminated'] = max(0, $panel['incoming_capacity'] - $panel['terminated_ports']);
        $panel['incoming'] = is_array($incomingRow) ? $incomingRow['label'] : '—';
        $panel['port_items'] = $ports;
        $panel['images'] = $this->assetImages('PATCH_PANEL', $id);

        return $panel;
    }

    public function assetImage(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, entity_type, entity_id, storage_path, original_name, mime_type, size_bytes, width_px, height_px, created_at
             FROM asset_images WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $image = $statement->fetch();
        return is_array($image) ? $this->normalizeAssetImage($image) : null;
    }

    public function addAssetImage(string $entityType, int $entityId, array $metadata): array
    {
        if (!in_array($entityType, ['RACK', 'PATCH_PANEL', 'LOCATION', 'SERVER_ROOM', 'CABLE'], true)) {
            throw new RuntimeException('Unsupported image entity type');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO asset_images (entity_type, entity_id, storage_path, original_name, mime_type, size_bytes, width_px, height_px)
             VALUES (:entity_type, :entity_id, :storage_path, :original_name, :mime_type, :size_bytes, :width_px, :height_px)',
        );
        $statement->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'storage_path' => $metadata['storage_path'],
            'original_name' => $metadata['original_name'],
            'mime_type' => $metadata['mime_type'],
            'size_bytes' => $metadata['size_bytes'],
            'width_px' => $metadata['width_px'],
            'height_px' => $metadata['height_px'],
        ]);
        $image = $this->assetImage((int) $this->pdo->lastInsertId());
        if ($image === null) {
            throw new RuntimeException('Image metadata could not be read');
        }
        return $image;
    }

    public function cables(): array
    {
        $rows = $this->pdo->query(
            'SELECT c.id, c.code, c.name, c.medium_type AS medium,
                c.declared_fiber_count AS fiber_count,
                c.operational_status,
                first_segment.length_m AS raw_length_m,
                source_node.location_id AS source_location_id,
                destination_node.location_id AS destination_location_id,
                CASE
                    WHEN source_node.rack_id IS NOT NULL THEN CONCAT("RACK:", source_node.rack_id)
                    WHEN source_node.server_room_id IS NOT NULL THEN CONCAT("ROOM:", source_node.server_room_id)
                    WHEN source_node.location_id IS NOT NULL THEN CONCAT("LOCATION:", source_node.location_id)
                    ELSE NULL
                END AS source_endpoint_key,
                CASE
                    WHEN destination_node.rack_id IS NOT NULL THEN CONCAT("RACK:", destination_node.rack_id)
                    WHEN destination_node.server_room_id IS NOT NULL THEN CONCAT("ROOM:", destination_node.server_room_id)
                    WHEN destination_node.location_id IS NOT NULL THEN CONCAT("LOCATION:", destination_node.location_id)
                    ELSE NULL
                END AS destination_endpoint_key,
                (SELECT COUNT(*) FROM cable_segments cs WHERE cs.cable_id = c.id) AS segments,
                COALESCE((SELECT SUM(cs.length_m) FROM cable_segments cs WHERE cs.cable_id = c.id), 0) AS length_m,
                (SELECT COUNT(*)
                 FROM cable_segments cs
                 JOIN fiber_strands fs ON fs.cable_segment_id = cs.id
                 WHERE cs.cable_id = c.id AND fs.operational_status = \'ACTIVE\') AS used,
                COALESCE(
                    CASE
                        WHEN source_node.rack_id IS NOT NULL THEN CONCAT(source_location.code, " · ", source_room.name, " · ", source_rack.code)
                        WHEN source_node.server_room_id IS NOT NULL THEN CONCAT(source_location.code, " · ", source_room.name)
                        WHEN source_node.location_id IS NOT NULL THEN CONCAT(source_location.code, " · ", source_location.name)
                        ELSE NULL
                    END,
                    "Topology node"
                ) AS source_name,
                (SELECT GROUP_CONCAT(DISTINCT
                    CASE
                        WHEN route_node.rack_id IS NOT NULL THEN CONCAT(route_location.code, " · ", route_room.name, " · ", route_rack.code)
                        WHEN route_node.server_room_id IS NOT NULL THEN CONCAT(route_location.code, " · ", route_room.name)
                        WHEN route_node.location_id IS NOT NULL THEN CONCAT(route_location.code, " · ", route_location.name)
                        ELSE NULL
                    END ORDER BY route_location.code, route_room.name, route_rack.code SEPARATOR "||")
                 FROM cable_segments route_segment
                 JOIN fiber_nodes route_node ON route_node.id = route_segment.z_node_id
                 LEFT JOIN locations route_location ON route_location.id = route_node.location_id
                 LEFT JOIN server_rooms route_room ON route_room.id = route_node.server_room_id
                 LEFT JOIN racks route_rack ON route_rack.id = route_node.rack_id
                 WHERE route_segment.cable_id = c.id) AS destination_names
             FROM cables c
             LEFT JOIN cable_segments first_segment ON first_segment.id = (
                 SELECT MIN(candidate_segment.id) FROM cable_segments candidate_segment WHERE candidate_segment.cable_id = c.id
             )
             LEFT JOIN fiber_nodes source_node ON source_node.id = first_segment.a_node_id
             LEFT JOIN locations source_location ON source_location.id = source_node.location_id
             LEFT JOIN server_rooms source_room ON source_room.id = source_node.server_room_id
             LEFT JOIN racks source_rack ON source_rack.id = source_node.rack_id
             LEFT JOIN fiber_nodes destination_node ON destination_node.id = first_segment.z_node_id
             WHERE c.archived_at IS NULL
             ORDER BY c.code',
        )->fetchAll();
        $accents = ['violet', 'cyan', 'amber', 'blue'];

        return array_map(function (array $row, int $index) use ($accents): array {
            return [
                'id' => (int) $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'medium' => $row['medium'],
                'fiber_count' => (int) $row['fiber_count'],
                'used' => min((int) $row['fiber_count'], (int) $row['used']),
                'status' => strtolower($row['operational_status']),
                'operational_status' => $row['operational_status'],
                'source_location_id' => (int) ($row['source_location_id'] ?? 0),
                'destination_location_id' => (int) ($row['destination_location_id'] ?? 0),
                'source_endpoint_key' => $row['source_endpoint_key'] ?? '',
                'destination_endpoint_key' => $row['destination_endpoint_key'] ?? '',
                'length_m' => (float) ($row['raw_length_m'] ?? 0),
                'source' => $row['source_name'],
                'destinations' => $row['destination_names'] ? explode('||', $row['destination_names']) : ['Multiple endpoints'],
                'length' => number_format(((float) $row['length_m']) / 1000, 2) . ' km',
                'segments' => (int) $row['segments'],
                'updated' => 'Live',
                'accent' => $accents[$index % count($accents)],
                'images' => $this->assetImages('CABLE', (int) $row['id']),
            ];
        }, $rows, array_keys($rows));
    }

    public function cableEndpointOptions(): array
    {
        $locations = $this->pdo->query(
            'SELECT id, code, name FROM locations WHERE archived_at IS NULL ORDER BY code, name',
        )->fetchAll();
        $rooms = $this->pdo->query(
            'SELECT sr.id, sr.code, sr.name, l.id AS location_id, l.code AS location_code, l.name AS location_name
             FROM server_rooms sr
             JOIN locations l ON l.id = sr.location_id
             WHERE sr.archived_at IS NULL AND l.archived_at IS NULL
             ORDER BY l.code, sr.name',
        )->fetchAll();
        $racks = $this->pdo->query(
            'SELECT r.id, r.code, r.name, sr.id AS server_room_id, sr.name AS room_name,
                l.id AS location_id, l.code AS location_code, l.name AS location_name
             FROM racks r
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE r.archived_at IS NULL AND sr.archived_at IS NULL AND l.archived_at IS NULL
             ORDER BY l.code, sr.name, r.code',
        )->fetchAll();

        return [
            'locations' => array_map(static fn (array $location): array => [
                'key' => 'LOCATION:' . $location['id'],
                'id' => (int) $location['id'],
                'location_id' => (int) $location['id'],
                'label' => $location['code'] . ' · ' . $location['name'],
            ], $locations),
            'rooms' => array_map(static fn (array $room): array => [
                'key' => 'ROOM:' . $room['id'],
                'id' => (int) $room['id'],
                'location_id' => (int) $room['location_id'],
                'label' => $room['location_code'] . ' · ' . $room['name'],
            ], $rooms),
            'racks' => array_map(static fn (array $rack): array => [
                'key' => 'RACK:' . $rack['id'],
                'id' => (int) $rack['id'],
                'location_id' => (int) $rack['location_id'],
                'server_room_id' => (int) $rack['server_room_id'],
                'label' => $rack['location_code'] . ' · ' . $rack['room_name'] . ' · ' . $rack['code'],
            ], $racks),
        ];
    }

    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $term = '%' . $query . '%';
        $sql = <<<'SQL'
SELECT type, entity_id, code, name, context, href
FROM (
    SELECT 'location' AS type, l.id AS entity_id, l.code, l.name,
        COALESCE(l.address, 'Building') AS context,
        CONCAT('/locations/', l.id) AS href
    FROM locations l
    WHERE l.archived_at IS NULL AND (l.code LIKE :q1 OR l.name LIKE :q2 OR l.address LIKE :q3)
    UNION ALL
    SELECT 'room', sr.id, sr.code, sr.name,
        CONCAT(l.name, IF(sr.floor IS NULL, '', CONCAT(' · Floor ', sr.floor))),
        CONCAT('/locations/', l.id)
    FROM server_rooms sr
    JOIN locations l ON l.id = sr.location_id
    WHERE sr.archived_at IS NULL AND (sr.code LIKE :q4 OR sr.name LIKE :q5 OR l.name LIKE :q6)
    UNION ALL
    SELECT 'rack', r.id, r.code, r.name,
        CONCAT(l.name, ' · ', sr.name), CONCAT('/racks/', r.id)
    FROM racks r
    JOIN server_rooms sr ON sr.id = r.server_room_id
    JOIN locations l ON l.id = sr.location_id
    WHERE r.archived_at IS NULL AND (r.code LIKE :q7 OR r.name LIKE :q8 OR sr.name LIKE :q9 OR l.name LIKE :q10)
    UNION ALL
    SELECT 'panel', pp.id, pp.code, pp.name,
        CONCAT(l.name, ' · ', sr.name, ' · ', r.code), CONCAT('/patch-panels/', pp.id)
    FROM patch_panels pp
    JOIN racks r ON r.id = pp.rack_id
    JOIN server_rooms sr ON sr.id = r.server_room_id
    JOIN locations l ON l.id = sr.location_id
    WHERE pp.archived_at IS NULL AND (pp.code LIKE :q11 OR pp.name LIKE :q12 OR r.code LIKE :q13 OR sr.name LIKE :q14)
    UNION ALL
    SELECT 'port', ppp.id, CONCAT(pp.code, ':', LPAD(ppp.port_number, 2, '0')),
        COALESCE(ppp.label, CONCAT('Port ', LPAD(ppp.port_number, 2, '0'))),
        CONCAT(l.name, ' · ', sr.name, ' · ', r.code), CONCAT('/patch-panels/', pp.id)
    FROM patch_panel_ports ppp
    JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
    JOIN racks r ON r.id = pp.rack_id
    JOIN server_rooms sr ON sr.id = r.server_room_id
    JOIN locations l ON l.id = sr.location_id
    WHERE pp.archived_at IS NULL AND (ppp.label LIKE :q15 OR pp.code LIKE :q16 OR CONCAT(pp.code, ':', ppp.port_number) LIKE :q17)
    UNION ALL
    SELECT 'cable', c.id, c.code, c.name,
        CONCAT(c.declared_fiber_count, 'J · ', c.medium_type), '/cables'
    FROM cables c
    WHERE c.archived_at IS NULL AND (c.code LIKE :q18 OR c.name LIKE :q19)
) results
ORDER BY FIELD(type, 'location', 'room', 'rack', 'panel', 'port', 'cable'), name
LIMIT 24
SQL;
        $statement = $this->pdo->prepare($sql);
        $parameters = [];
        for ($index = 1; $index <= 19; $index++) {
            $parameters['q' . $index] = $term;
        }
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'type' => $row['type'],
            'id' => (int) $row['entity_id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'context' => $row['context'],
            'href' => $row['href'],
        ], $statement->fetchAll());
    }

    public function connectorTypes(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, code FROM connector_types WHERE active = TRUE ORDER BY FIELD(code, "LC", "SC-APC", "SC-PC", "E2000"), code',
        )->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => $row['code'],
        ], $rows);
    }

    public function activeDeviceOptions(): array
    {
        $rackRows = $this->pdo->query(
            'SELECT r.id, r.code, r.name, sr.name AS room, l.name AS location
             FROM racks r
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE r.archived_at IS NULL AND sr.archived_at IS NULL AND l.archived_at IS NULL
             ORDER BY l.name, sr.name, r.code',
        )->fetchAll();
        $deviceRows = $this->pdo->query(
            'SELECT ad.id, ad.rack_id, ad.code, ad.name, ad.device_type, ad.vendor, ad.model,
                r.code AS rack, sr.name AS room, l.name AS location
             FROM active_devices ad
             JOIN racks r ON r.id = ad.rack_id
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE ad.archived_at IS NULL AND r.archived_at IS NULL
             ORDER BY l.name, sr.name, r.code, ad.name',
        )->fetchAll();

        return [
            'racks' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'room' => $row['room'],
                'location' => $row['location'],
                'label' => implode(' · ', [$row['location'], $row['room'], $row['code'], $row['name']]),
            ], $rackRows),
            'devices' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'rack_id' => (int) $row['rack_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'device_type' => $row['device_type'],
                'vendor' => $row['vendor'],
                'model' => $row['model'],
                'rack' => $row['rack'],
                'room' => $row['room'],
                'location' => $row['location'],
                'label' => implode(' · ', array_filter([$row['location'], $row['rack'], $row['vendor'], $row['name'], $row['model']])),
            ], $deviceRows),
        ];
    }

    public function createLocation(array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO locations (code, name, icon_key, address) VALUES (:code, :name, :icon_key, :address)',
            );
            $statement->execute([
                'code' => strtoupper(trim((string) $input['code'])),
                'name' => trim((string) $input['name']),
                'icon_key' => trim((string) ($input['icon_key'] ?? '')) ?: 'loc-office',
                'address' => trim((string) ($input['address'] ?? '')) ?: null,
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $record = ['id' => $id, 'code' => strtoupper(trim((string) $input['code'])), 'name' => trim((string) $input['name']), 'icon_key' => trim((string) ($input['icon_key'] ?? '')) ?: 'loc-office', 'address' => trim((string) ($input['address'] ?? ''))];
            $this->recordAudit('LOCATION', $id, 'CREATE', null, $record);
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updateLocation(int $locationId, array $input): array
    {
        $statement = $this->pdo->prepare(
            'UPDATE locations SET code = :code, name = :name, icon_key = :icon_key, address = :address WHERE id = :id AND archived_at IS NULL',
        );
        $record = [
            'id' => $locationId,
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'icon_key' => trim((string) ($input['icon_key'] ?? '')) ?: 'loc-office',
            'address' => trim((string) ($input['address'] ?? '')) ?: null,
        ];
        $statement->execute($record);
        if ($statement->rowCount() === 0 && !$this->recordExists('locations', $locationId)) {
            throw new RuntimeException('Location not found');
        }
        $this->recordAudit('LOCATION', $locationId, 'UPDATE', null, $record);
        return $record;
    }

    public function archiveLocation(int $locationId): array
    {
        return $this->archiveContainer(
            'locations',
            'LOCATION',
            $locationId,
            'location_id = :id AND server_room_id IS NULL AND rack_id IS NULL',
            [
                [
                    'SELECT COUNT(*) FROM server_rooms WHERE location_id = :id AND archived_at IS NULL',
                    'location_has_rooms',
                ],
                [
                    'SELECT COUNT(*) FROM fiber_nodes
                     WHERE location_id = :id AND server_room_id IS NULL AND rack_id IS NULL
                       AND archived_at IS NULL AND node_type <> "BUILDING_ENTRY"',
                    'location_has_fiber_nodes',
                ],
                [
                    'SELECT COUNT(DISTINCT c.id)
                     FROM cables c
                     JOIN cable_segments cs ON cs.cable_id = c.id
                     JOIN fiber_nodes fn ON fn.id IN (cs.a_node_id, cs.z_node_id)
                     WHERE c.archived_at IS NULL AND fn.location_id = :id
                       AND fn.server_room_id IS NULL AND fn.rack_id IS NULL AND fn.archived_at IS NULL',
                    'location_used_by_cable',
                ],
            ],
        );
    }

    public function createServerRoom(int $locationId, array $input): array
    {
        $sequence = $this->pdo->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)), 0) + 1
             FROM server_rooms
             WHERE location_id = :location_id AND code REGEXP "^SR-[0-9]+$"',
        );
        $sequence->execute(['location_id' => $locationId]);
        $code = sprintf('SR-%03d', (int) $sequence->fetchColumn());
        $statement = $this->pdo->prepare(
            'INSERT INTO server_rooms (location_id, code, name, floor) VALUES (:location_id, :code, :name, :floor)',
        );
        $statement->execute([
            'location_id' => $locationId,
            'code' => $code,
            'name' => trim((string) $input['name']),
            'floor' => trim((string) ($input['floor'] ?? '')) ?: null,
        ]);

        $record = [
            'id' => (int) $this->pdo->lastInsertId(),
            'location_id' => $locationId,
            'code' => $code,
            'name' => trim((string) $input['name']),
            'floor' => trim((string) ($input['floor'] ?? '')),
        ];
        $this->recordAudit('SERVER_ROOM', $record['id'], 'CREATE', null, $record);
        return $record;
    }

    public function updateServerRoom(int $serverRoomId, array $input): array
    {
        $statement = $this->pdo->prepare(
            'UPDATE server_rooms SET location_id = :location_id, name = :name, floor = :floor WHERE id = :id AND archived_at IS NULL',
        );
        $record = [
            'id' => $serverRoomId,
            'location_id' => (int) $input['location_id'],
            'name' => trim((string) $input['name']),
            'floor' => trim((string) ($input['floor'] ?? '')) ?: null,
        ];
        $statement->execute($record);
        if ($statement->rowCount() === 0 && !$this->recordExists('server_rooms', $serverRoomId)) {
            throw new RuntimeException('Server room not found');
        }
        $this->recordAudit('SERVER_ROOM', $serverRoomId, 'UPDATE', null, $record);
        return $record;
    }

    public function archiveServerRoom(int $serverRoomId): array
    {
        return $this->archiveContainer(
            'server_rooms',
            'SERVER_ROOM',
            $serverRoomId,
            'server_room_id = :id AND rack_id IS NULL',
            [
                [
                    'SELECT COUNT(*) FROM racks WHERE server_room_id = :id AND archived_at IS NULL',
                    'room_has_racks',
                ],
                [
                    'SELECT COUNT(*) FROM ups_devices WHERE server_room_id = :id AND archived_at IS NULL',
                    'room_has_ups',
                ],
                [
                    'SELECT COUNT(*) FROM fiber_nodes
                     WHERE server_room_id = :id AND rack_id IS NULL
                       AND archived_at IS NULL AND node_type <> "BUILDING_ENTRY"',
                    'room_has_fiber_nodes',
                ],
                [
                    'SELECT COUNT(DISTINCT c.id)
                     FROM cables c
                     JOIN cable_segments cs ON cs.cable_id = c.id
                     JOIN fiber_nodes fn ON fn.id IN (cs.a_node_id, cs.z_node_id)
                     WHERE c.archived_at IS NULL AND fn.server_room_id = :id
                       AND fn.rack_id IS NULL AND fn.archived_at IS NULL',
                    'room_used_by_cable',
                ],
            ],
        );
    }

    public function createUpsDevice(int $serverRoomId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $room = $this->pdo->prepare(
                'SELECT id FROM server_rooms WHERE id = :id AND archived_at IS NULL FOR UPDATE',
            );
            $room->execute(['id' => $serverRoomId]);
            if ($room->fetchColumn() === false) {
                throw new RuntimeException('Server room not found');
            }

            $temporaryCode = 'UPS-PENDING-' . bin2hex(random_bytes(8));
            $statement = $this->pdo->prepare(
                'INSERT INTO ups_devices (
                    server_room_id, code, name, manufacturer, model, serial_number,
                    rated_power_va, rated_power_w, ip_address, management_url,
                    battery_replaced_at, battery_replacement_interval_months,
                    battery_count, battery_type,
                    operational_status, notes
                 ) VALUES (
                    :server_room_id, :code, :name, :manufacturer, :model, :serial_number,
                    :rated_power_va, :rated_power_w, :ip_address, :management_url,
                    :battery_replaced_at, :battery_replacement_interval_months,
                    :battery_count, :battery_type,
                    :operational_status, :notes
                 )',
            );
            $record = $this->upsDeviceRecord($input) + [
                'server_room_id' => $serverRoomId,
                'code' => $temporaryCode,
            ];
            $statement->execute($record);
            $upsDeviceId = (int) $this->pdo->lastInsertId();
            $code = sprintf('UPS-SR%d-%04d', $serverRoomId, $upsDeviceId);
            $updateCode = $this->pdo->prepare('UPDATE ups_devices SET code = :code WHERE id = :id');
            $updateCode->execute(['id' => $upsDeviceId, 'code' => $code]);
            $upsDeviceRecord = $this->upsDevice($upsDeviceId) ?? throw new RuntimeException('UPS device could not be loaded');
            $this->recordAudit('UPS_DEVICE', $upsDeviceId, 'CREATE', null, $upsDeviceRecord);
            $this->pdo->commit();

            return $upsDeviceRecord;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updateUpsDevice(int $upsDeviceId, array $input): array
    {
        $record = $this->upsDeviceRecord($input) + ['id' => $upsDeviceId];
        $statement = $this->pdo->prepare(
            'UPDATE ups_devices SET
                name = :name, manufacturer = :manufacturer, model = :model,
                serial_number = :serial_number, rated_power_va = :rated_power_va,
                rated_power_w = :rated_power_w, ip_address = :ip_address,
                management_url = :management_url, battery_replaced_at = :battery_replaced_at,
                battery_replacement_interval_months = :battery_replacement_interval_months,
                battery_count = :battery_count, battery_type = :battery_type,
                operational_status = :operational_status, notes = :notes
             WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute($record);
        $upsDevice = $this->upsDevice($upsDeviceId);
        if ($upsDevice === null) {
            throw new RuntimeException('UPS device not found');
        }
        $this->recordAudit('UPS_DEVICE', $upsDeviceId, 'UPDATE', null, $upsDevice);
        return $upsDevice;
    }

    public function archiveUpsDevice(int $upsDeviceId): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, code, name FROM ups_devices WHERE id = :id AND archived_at IS NULL FOR UPDATE',
            );
            $statement->execute(['id' => $upsDeviceId]);
            $upsDevice = $statement->fetch();
            if (!is_array($upsDevice)) {
                throw new RuntimeException('UPS device not found');
            }
            $archive = $this->pdo->prepare(
                'UPDATE ups_devices SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL',
            );
            $archive->execute(['id' => $upsDeviceId]);
            $this->recordArchiveAudit('UPS_DEVICE', $upsDeviceId, $upsDevice);
            $this->pdo->commit();
            return ['id' => $upsDeviceId, 'code' => $upsDevice['code'], 'archived' => true];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function createRack(int $serverRoomId, array $input): array
    {
        $position = $this->pdo->prepare(
            'SELECT COALESCE(MAX(position_index), 0) + 1 FROM racks WHERE server_room_id = :server_room_id',
        );
        $position->execute(['server_room_id' => $serverRoomId]);
        $positionIndex = (int) $position->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO racks (server_room_id, code, name, total_units, row_label, position_index)
             VALUES (:server_room_id, :code, :name, :total_units, :row_label, :position_index)',
        );
        $statement->execute([
            'server_room_id' => $serverRoomId,
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'total_units' => (int) $input['total_units'],
            'row_label' => trim((string) ($input['row_label'] ?? '')) ?: null,
            'position_index' => $positionIndex,
        ]);

        $record = [
            'id' => (int) $this->pdo->lastInsertId(),
            'server_room_id' => $serverRoomId,
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'units_used' => 0,
            'units_total' => (int) $input['total_units'],
            'panels' => 0,
        ];
        $this->recordAudit('RACK', $record['id'], 'CREATE', null, $record);
        return $record;
    }

    public function updateRack(int $rackId, array $input): array
    {
        $highestPanel = $this->pdo->prepare(
            'SELECT COALESCE(MAX(rack_unit_start), 0) FROM patch_panels WHERE rack_id = :rack_id AND archived_at IS NULL',
        );
        $highestPanel->execute(['rack_id' => $rackId]);
        if ((int) $input['total_units'] < (int) $highestPanel->fetchColumn()) {
            throw new RuntimeException('Rack cannot be smaller than its highest mounted panel');
        }
        $statement = $this->pdo->prepare(
            'UPDATE racks SET code = :code, name = :name, total_units = :total_units, row_label = :row_label
             WHERE id = :id AND archived_at IS NULL',
        );
        $record = [
            'id' => $rackId,
            'code' => strtoupper(trim((string) $input['code'])),
            'name' => trim((string) $input['name']),
            'total_units' => (int) $input['total_units'],
            'row_label' => trim((string) ($input['row_label'] ?? '')) ?: null,
        ];
        $statement->execute($record);
        if ($statement->rowCount() === 0 && !$this->recordExists('racks', $rackId)) {
            throw new RuntimeException('Rack not found');
        }
        $this->recordAudit('RACK', $rackId, 'UPDATE', null, $record);
        return $record;
    }

    public function archiveRack(int $rackId): array
    {
        return $this->archiveContainer(
            'racks',
            'RACK',
            $rackId,
            'rack_id = :id',
            [
                [
                    'SELECT COUNT(*) FROM patch_panels WHERE rack_id = :id AND archived_at IS NULL',
                    'rack_has_panels',
                ],
                [
                    'SELECT COUNT(*) FROM active_devices WHERE rack_id = :id AND archived_at IS NULL',
                    'rack_has_devices',
                ],
                [
                    'SELECT COUNT(*) FROM rack_items WHERE rack_id = :id AND archived_at IS NULL',
                    'rack_has_devices',
                ],
                [
                    'SELECT COUNT(DISTINCT c.id)
                     FROM cables c
                     JOIN cable_segments cs ON cs.cable_id = c.id
                     JOIN fiber_nodes fn ON fn.id IN (cs.a_node_id, cs.z_node_id)
                     WHERE c.archived_at IS NULL AND fn.rack_id = :id AND fn.archived_at IS NULL',
                    'rack_used_by_cable',
                ],
            ],
        );
    }

    public function createPatchPanel(int $rackId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $rackStatement = $this->pdo->prepare(
                'SELECT r.id, r.code, r.total_units, sr.id AS server_room_id, sr.location_id
                 FROM racks r
                 JOIN server_rooms sr ON sr.id = r.server_room_id
                 WHERE r.id = :id AND r.archived_at IS NULL FOR UPDATE',
            );
            $rackStatement->execute(['id' => $rackId]);
            $rack = $rackStatement->fetch();
            if (!is_array($rack)) {
                throw new RuntimeException('Rack not found');
            }

            $start = (int) $input['rack_unit_start'];
            $height = (int) $input['rack_unit_height'];
            $lowestUnit = $start - $height + 1;
            if ($lowestUnit < 1 || $start > (int) $rack['total_units']) {
                throw new RuntimeException('Panel position is outside the rack');
            }
            $overlap = $this->pdo->prepare(
                'SELECT COUNT(*) FROM patch_panels
                 WHERE rack_id = :rack_id AND archived_at IS NULL
                   AND (rack_unit_start - rack_unit_height + 1) <= :new_start
                   AND rack_unit_start >= :new_lowest',
            );
            $overlap->execute(['rack_id' => $rackId, 'new_start' => $start, 'new_lowest' => $lowestUnit]);
            if ((int) $overlap->fetchColumn() > 0) {
                throw new RuntimeException('Selected rack units are already occupied');
            }

            $sequence = $this->pdo->prepare(
                'SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(code, "-", -1) AS UNSIGNED)), 0) + 1
                 FROM patch_panels WHERE rack_id = :rack_id',
            );
            $sequence->execute(['rack_id' => $rackId]);
            $code = sprintf('PP-R%d-%03d', $rackId, (int) $sequence->fetchColumn());
            $nodeStatement = $this->pdo->prepare(
                'INSERT INTO fiber_nodes (location_id, server_room_id, rack_id, node_type, code, name)
                 VALUES (:location_id, :server_room_id, :rack_id, "PATCH_PANEL", :code, :name)',
            );
            $nodeStatement->execute([
                'location_id' => $rack['location_id'],
                'server_room_id' => $rack['server_room_id'],
                'rack_id' => $rackId,
                'code' => 'NODE-' . $code,
                'name' => trim((string) $input['name']),
            ]);
            $nodeId = (int) $this->pdo->lastInsertId();

            $panelStatement = $this->pdo->prepare(
                'INSERT INTO patch_panels
                    (rack_id, fiber_node_id, code, name, rack_unit_start, rack_unit_height, port_count, layout_rows, layout_columns, manufacturer, model)
                 VALUES
                    (:rack_id, :fiber_node_id, :code, :name, :rack_unit_start, :rack_unit_height, :port_count, :layout_rows, :layout_columns, :manufacturer, :model)',
            );
            $panelStatement->execute([
                'rack_id' => $rackId,
                'fiber_node_id' => $nodeId,
                'code' => $code,
                'name' => trim((string) $input['name']),
                'rack_unit_start' => $start,
                'rack_unit_height' => $height,
                'port_count' => (int) $input['port_count'],
                'layout_rows' => (int) $input['layout_rows'],
                'layout_columns' => (int) $input['layout_columns'],
                'manufacturer' => trim((string) ($input['manufacturer'] ?? '')) ?: null,
                'model' => trim((string) ($input['model'] ?? '')) ?: null,
            ]);
            $panelId = (int) $this->pdo->lastInsertId();

            $portStatement = $this->pdo->prepare(
                'INSERT INTO patch_panel_ports
                    (patch_panel_id, connector_type_id, port_number, layout_row, layout_column)
                 VALUES (:panel_id, :connector_type_id, :port_number, :layout_row, :layout_column)',
            );
            $columns = (int) $input['layout_columns'];
            for ($portNumber = 1; $portNumber <= (int) $input['port_count']; $portNumber++) {
                $portStatement->execute([
                    'panel_id' => $panelId,
                    'connector_type_id' => (int) $input['connector_type_id'],
                    'port_number' => $portNumber,
                    'layout_row' => (int) floor(($portNumber - 1) / $columns) + 1,
                    'layout_column' => (($portNumber - 1) % $columns) + 1,
                ]);
            }

            $record = ['id' => $panelId, 'rack_id' => $rackId, 'code' => $code, 'name' => trim((string) $input['name'])];
            $this->recordAudit('PATCH_PANEL', $panelId, 'CREATE', null, $record);
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updatePatchPanel(int $panelId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $panelStatement = $this->pdo->prepare(
                'SELECT pp.id, pp.rack_id, pp.fiber_node_id, pp.port_count, r.total_units
                 FROM patch_panels pp JOIN racks r ON r.id = pp.rack_id
                 WHERE pp.id = :id AND pp.archived_at IS NULL FOR UPDATE',
            );
            $panelStatement->execute(['id' => $panelId]);
            $panel = $panelStatement->fetch();
            if (!is_array($panel)) {
                throw new RuntimeException('Patch panel not found');
            }
            $start = (int) $input['rack_unit_start'];
            $height = (int) $input['rack_unit_height'];
            $lowestUnit = $start - $height + 1;
            if ($lowestUnit < 1 || $start > (int) $panel['total_units']) {
                throw new RuntimeException('Panel position is outside the rack');
            }
            $overlap = $this->pdo->prepare(
                'SELECT COUNT(*) FROM patch_panels
                 WHERE rack_id = :rack_id AND id <> :panel_id AND archived_at IS NULL
                   AND (rack_unit_start - rack_unit_height + 1) <= :new_start
                   AND rack_unit_start >= :new_lowest',
            );
            $overlap->execute(['rack_id' => $panel['rack_id'], 'panel_id' => $panelId, 'new_start' => $start, 'new_lowest' => $lowestUnit]);
            if ((int) $overlap->fetchColumn() > 0) {
                throw new RuntimeException('Selected rack units are already occupied');
            }
            $newPortCount = (int) $input['port_count'];
            if ($newPortCount < (int) $panel['port_count']) {
                $protectedPorts = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM patch_panel_ports ppp
                     WHERE ppp.patch_panel_id = :panel_id AND ppp.port_number > :port_count
                       AND (
                           EXISTS (SELECT 1 FROM fiber_connection_ports fcp WHERE fcp.patch_panel_port_id = ppp.id)
                           OR EXISTS (SELECT 1 FROM patch_cord_connections pc WHERE pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                           OR EXISTS (SELECT 1 FROM rear_fiber_connection_ports rfcp WHERE rfcp.patch_panel_port_id = ppp.id)
                           OR EXISTS (SELECT 1 FROM patch_panel_front_connections pfc WHERE pfc.patch_panel_port_id = ppp.id)
                           OR EXISTS (SELECT 1 FROM front_panel_connection_ports fpcp WHERE fpcp.patch_panel_port_id = ppp.id)
                       )',
                );
                $protectedPorts->execute(['panel_id' => $panelId, 'port_count' => $newPortCount]);
                if ((int) $protectedPorts->fetchColumn() > 0) {
                    throw new RuntimeException('Connected ports must be disconnected before reducing the panel size');
                }
                $deletePorts = $this->pdo->prepare(
                    'DELETE FROM patch_panel_ports WHERE patch_panel_id = :panel_id AND port_number > :port_count',
                );
                $deletePorts->execute(['panel_id' => $panelId, 'port_count' => $newPortCount]);
            }
            $columns = (int) $input['layout_columns'];
            $connectorTypeId = (int) $input['connector_type_id'];
            $temporaryLayout = $this->pdo->prepare(
                'UPDATE patch_panel_ports SET layout_row = layout_row + 1000 WHERE patch_panel_id = :panel_id',
            );
            $temporaryLayout->execute(['panel_id' => $panelId]);
            $updatePort = $this->pdo->prepare(
                'UPDATE patch_panel_ports SET connector_type_id = :connector_type_id, layout_row = :layout_row, layout_column = :layout_column
                 WHERE patch_panel_id = :panel_id AND port_number = :port_number',
            );
            $insertPort = $this->pdo->prepare(
                'INSERT INTO patch_panel_ports (patch_panel_id, connector_type_id, port_number, layout_row, layout_column)
                 VALUES (:panel_id, :connector_type_id, :port_number, :layout_row, :layout_column)',
            );
            for ($portNumber = 1; $portNumber <= $newPortCount; $portNumber++) {
                $parameters = [
                    'panel_id' => $panelId,
                    'connector_type_id' => $connectorTypeId,
                    'port_number' => $portNumber,
                    'layout_row' => (int) floor(($portNumber - 1) / $columns) + 1,
                    'layout_column' => (($portNumber - 1) % $columns) + 1,
                ];
                if ($portNumber <= (int) $panel['port_count']) {
                    $updatePort->execute($parameters);
                } else {
                    $insertPort->execute($parameters);
                }
            }
            $code = strtoupper(trim((string) $input['code']));
            $name = trim((string) $input['name']);
            $updatePanel = $this->pdo->prepare(
                'UPDATE patch_panels SET code = :code, name = :name, rack_unit_start = :rack_unit_start,
                    rack_unit_height = :rack_unit_height, port_count = :port_count, layout_rows = :layout_rows,
                    layout_columns = :layout_columns, manufacturer = :manufacturer, model = :model
                 WHERE id = :id',
            );
            $updatePanel->execute([
                'id' => $panelId,
                'code' => $code,
                'name' => $name,
                'rack_unit_start' => $start,
                'rack_unit_height' => $height,
                'port_count' => $newPortCount,
                'layout_rows' => (int) $input['layout_rows'],
                'layout_columns' => $columns,
                'manufacturer' => trim((string) ($input['manufacturer'] ?? '')) ?: null,
                'model' => trim((string) ($input['model'] ?? '')) ?: null,
            ]);
            $updateNode = $this->pdo->prepare('UPDATE fiber_nodes SET code = :code, name = :name WHERE id = :id');
            $updateNode->execute(['id' => $panel['fiber_node_id'], 'code' => 'NODE-' . $code, 'name' => $name]);
            $record = ['id' => $panelId, 'code' => $code, 'name' => $name];
            $this->recordAudit('PATCH_PANEL', $panelId, 'UPDATE', null, $record);
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function archivePatchPanel(int $panelId): array
    {
        $this->pdo->beginTransaction();
        try {
            $panelStatement = $this->pdo->prepare(
                'SELECT id, code, name, fiber_node_id
                 FROM patch_panels WHERE id = :id AND archived_at IS NULL FOR UPDATE',
            );
            $panelStatement->execute(['id' => $panelId]);
            $panel = $panelStatement->fetch();
            if (!is_array($panel)) {
                throw new RuntimeException('Patch panel not found');
            }

            $portUsage = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM patch_panel_ports ppp
                 WHERE ppp.patch_panel_id = :id
                   AND (
                       ppp.administrative_status <> "AVAILABLE"
                       OR EXISTS (SELECT 1 FROM fiber_connection_ports fcp WHERE fcp.patch_panel_port_id = ppp.id)
                       OR EXISTS (
                           SELECT 1 FROM patch_cord_connections pc
                           WHERE (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                             AND pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                       )
                       OR EXISTS (
                           SELECT 1 FROM rear_fiber_connection_ports rfcp
                           JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
                           WHERE rfcp.patch_panel_port_id = ppp.id
                             AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                       )
                       OR EXISTS (SELECT 1 FROM patch_panel_front_connections pfc WHERE pfc.patch_panel_port_id = ppp.id)
                       OR EXISTS (
                           SELECT 1 FROM front_panel_connection_ports fpcp
                           JOIN front_panel_connections fpc ON fpc.id = fpcp.front_panel_connection_id
                           WHERE fpcp.patch_panel_port_id = ppp.id
                             AND fpc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                       )
                   )',
            );
            $portUsage->execute(['id' => $panelId]);
            if ((int) $portUsage->fetchColumn() > 0) {
                throw new ResourceInUseException('panel_has_used_ports');
            }

            $nodeUsage = $this->pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM fiber_connections fc WHERE fc.fiber_node_id = :connection_node_id)
                    +
                    (SELECT COUNT(DISTINCT c.id)
                     FROM cables c
                     JOIN cable_segments cs ON cs.cable_id = c.id
                     WHERE c.archived_at IS NULL
                       AND (cs.a_node_id = :a_node_id OR cs.z_node_id = :z_node_id))',
            );
            $nodeUsage->execute([
                'connection_node_id' => $panel['fiber_node_id'],
                'a_node_id' => $panel['fiber_node_id'],
                'z_node_id' => $panel['fiber_node_id'],
            ]);
            if ((int) $nodeUsage->fetchColumn() > 0) {
                throw new ResourceInUseException('panel_used_by_fiber_route');
            }

            $archivePanel = $this->pdo->prepare(
                'UPDATE patch_panels SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL',
            );
            $archivePanel->execute(['id' => $panelId]);
            $archiveNode = $this->pdo->prepare(
                'UPDATE fiber_nodes SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL',
            );
            $archiveNode->execute(['id' => $panel['fiber_node_id']]);
            $this->recordArchiveAudit('PATCH_PANEL', $panelId, $panel);
            $this->pdo->commit();
            return ['id' => $panelId, 'code' => $panel['code'], 'archived' => true];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updateActiveDevice(int $activeDeviceId, array $input): array
    {
        $record = [
            'id' => $activeDeviceId,
            'name' => trim((string) $input['name']),
            'device_type' => strtoupper(trim((string) $input['device_type'])),
            'vendor' => trim((string) $input['vendor']),
            'model' => trim((string) ($input['model'] ?? '')) ?: null,
            'management_address' => trim((string) ($input['management_address'] ?? '')) ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
        $statement = $this->pdo->prepare(
            'UPDATE active_devices SET
                name = :name, device_type = :device_type, vendor = :vendor, model = :model,
                management_address = :management_address, notes = :notes
             WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute($record);
        if ($statement->rowCount() === 0 && !$this->recordExists('active_devices', $activeDeviceId)) {
            throw new RuntimeException('Active device not found');
        }
        $this->recordAudit('ACTIVE_DEVICE', $activeDeviceId, 'UPDATE', null, $record);
        return $record;
    }

    public function archiveActiveDevice(int $activeDeviceId): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, code, name FROM active_devices WHERE id = :id AND archived_at IS NULL FOR UPDATE',
            );
            $statement->execute(['id' => $activeDeviceId]);
            $device = $statement->fetch();
            if (!is_array($device)) {
                throw new RuntimeException('Active device not found');
            }
            $connected = $this->pdo->prepare(
                'SELECT COUNT(*) FROM active_device_interfaces adi
                 JOIN patch_panel_front_connections pfc ON pfc.active_device_interface_id = adi.id
                 WHERE adi.active_device_id = :id',
            );
            $connected->execute(['id' => $activeDeviceId]);
            if ((int) $connected->fetchColumn() > 0) {
                throw new ResourceInUseException('active_device_connected');
            }
            $archive = $this->pdo->prepare(
                'UPDATE active_devices SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL',
            );
            $archive->execute(['id' => $activeDeviceId]);
            $this->recordArchiveAudit('ACTIVE_DEVICE', $activeDeviceId, $device);
            $this->pdo->commit();
            return ['id' => $activeDeviceId, 'code' => $device['code'], 'archived' => true];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function createRackItem(int $rackId, array $input): array
    {
        $rackStatement = $this->pdo->prepare('SELECT id FROM racks WHERE id = :id AND archived_at IS NULL');
        $rackStatement->execute(['id' => $rackId]);
        if ($rackStatement->fetchColumn() === false) {
            throw new RuntimeException('Rack not found');
        }
        $record = [
            'rack_id' => $rackId,
            'name' => trim((string) $input['name']),
            'kind' => strtoupper(trim((string) $input['kind'])),
            'rack_unit_start' => (int) $input['rack_unit_start'],
            'rack_unit_height' => (int) $input['rack_unit_height'],
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
        $statement = $this->pdo->prepare(
            'INSERT INTO rack_items (rack_id, name, kind, rack_unit_start, rack_unit_height, notes)
             VALUES (:rack_id, :name, :kind, :rack_unit_start, :rack_unit_height, :notes)',
        );
        $statement->execute($record);
        $record['id'] = (int) $this->pdo->lastInsertId();
        $this->recordAudit('RACK_ITEM', $record['id'], 'CREATE', null, $record);
        return $record;
    }

    public function updateRackItem(int $rackItemId, array $input): array
    {
        $record = [
            'id' => $rackItemId,
            'name' => trim((string) $input['name']),
            'kind' => strtoupper(trim((string) $input['kind'])),
            'rack_unit_start' => (int) $input['rack_unit_start'],
            'rack_unit_height' => (int) $input['rack_unit_height'],
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
        $statement = $this->pdo->prepare(
            'UPDATE rack_items SET name = :name, kind = :kind, rack_unit_start = :rack_unit_start,
                rack_unit_height = :rack_unit_height, notes = :notes
             WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute($record);
        if ($statement->rowCount() === 0 && !$this->recordExists('rack_items', $rackItemId)) {
            throw new RuntimeException('Rack item not found');
        }
        $this->recordAudit('RACK_ITEM', $rackItemId, 'UPDATE', null, $record);
        return $record;
    }

    public function archiveRackItem(int $rackItemId): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, name FROM rack_items WHERE id = :id AND archived_at IS NULL FOR UPDATE',
            );
            $statement->execute(['id' => $rackItemId]);
            $item = $statement->fetch();
            if (!is_array($item)) {
                throw new RuntimeException('Rack item not found');
            }
            $archive = $this->pdo->prepare(
                'UPDATE rack_items SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL',
            );
            $archive->execute(['id' => $rackItemId]);
            $this->recordArchiveAudit('RACK_ITEM', $rackItemId, $item);
            $this->pdo->commit();
            return ['id' => $rackItemId, 'name' => $item['name'], 'archived' => true];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updatePort(int $portId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $portStatement = $this->pdo->prepare(
                'SELECT ppp.id, pp.rack_id
                 FROM patch_panel_ports ppp
                 JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
                 WHERE ppp.id = :id FOR UPDATE',
            );
            $portStatement->execute(['id' => $portId]);
            $port = $portStatement->fetch();
            if (!is_array($port)) {
                throw new RuntimeException('Port not found');
            }

            $record = [
                'id' => $portId,
                'connector_type_id' => (int) $input['connector_type_id'],
                'label' => trim((string) ($input['label'] ?? '')) ?: null,
                'status' => strtoupper(trim((string) $input['administrative_status'])),
                'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
                'highlight_color' => trim((string) ($input['highlight_color'] ?? '')) ?: null,
            ];
            $statement = $this->pdo->prepare(
                'UPDATE patch_panel_ports
                 SET connector_type_id = :connector_type_id, label = :label,
                     administrative_status = :status, notes = :notes, highlight_color = :highlight_color
                 WHERE id = :id',
            );
            $statement->execute($record);

            $rearMode = strtoupper(trim((string) ($input['rear_connection_mode'] ?? 'UNCHANGED')));
            if ($rearMode === 'NONE') {
                $this->removeRearFiberConnection($portId);
            }

            $frontMode = strtoupper(trim((string) ($input['front_connection_mode'] ?? 'UNCHANGED')));
            if ($frontMode === 'NONE') {
                $delete = $this->pdo->prepare('DELETE FROM patch_panel_front_connections WHERE patch_panel_port_id = :port_id');
                $delete->execute(['port_id' => $portId]);
                $this->removeFrontPanelConnection($portId);
            } elseif ($frontMode === 'DEVICE') {
                $this->removeFrontPanelConnection($portId);
                $deviceId = (int) ($input['active_device_id'] ?? 0);
                if ($deviceId > 0) {
                    $deviceCheck = $this->pdo->prepare('SELECT id FROM active_devices WHERE id = :id AND archived_at IS NULL');
                    $deviceCheck->execute(['id' => $deviceId]);
                    if ($deviceCheck->fetchColumn() === false) {
                        throw new RuntimeException('Active device not found');
                    }
                } else {
                    $deviceCode = 'DEV-' . strtoupper(bin2hex(random_bytes(4)));
                    $deviceInsert = $this->pdo->prepare(
                        'INSERT INTO active_devices (rack_id, code, name, device_type, vendor, model)
                         VALUES (:rack_id, :code, :name, :device_type, :vendor, :model)',
                    );
                    $deviceInsert->execute([
                        'rack_id' => (int) ($input['active_device_rack_id'] ?? $port['rack_id']),
                        'code' => $deviceCode,
                        'name' => trim((string) $input['active_device_name']),
                        'device_type' => strtoupper(trim((string) $input['active_device_type'])),
                        'vendor' => trim((string) $input['active_device_vendor']),
                        'model' => trim((string) ($input['active_device_model'] ?? '')) ?: null,
                    ]);
                    $deviceId = (int) $this->pdo->lastInsertId();
                }

                $interfaceName = trim((string) $input['active_interface_name']);
                $interfaceFind = $this->pdo->prepare(
                    'SELECT adi.id, pfc.patch_panel_port_id
                     FROM active_device_interfaces adi
                     LEFT JOIN patch_panel_front_connections pfc ON pfc.active_device_interface_id = adi.id
                     WHERE adi.active_device_id = :device_id AND adi.name = :name FOR UPDATE',
                );
                $interfaceFind->execute(['device_id' => $deviceId, 'name' => $interfaceName]);
                $interface = $interfaceFind->fetch();
                if (is_array($interface)) {
                    if ($interface['patch_panel_port_id'] !== null && (int) $interface['patch_panel_port_id'] !== $portId) {
                        throw new RuntimeException('The selected device interface is already connected to another patch-panel port');
                    }
                    $interfaceId = (int) $interface['id'];
                    $interfaceUpdate = $this->pdo->prepare(
                        'UPDATE active_device_interfaces SET interface_type = :interface_type, speed_label = :speed_label WHERE id = :id',
                    );
                    $interfaceUpdate->execute([
                        'id' => $interfaceId,
                        'interface_type' => strtoupper(trim((string) $input['active_interface_type'])),
                        'speed_label' => trim((string) ($input['active_interface_speed'] ?? '')) ?: null,
                    ]);
                } else {
                    $interfaceInsert = $this->pdo->prepare(
                        'INSERT INTO active_device_interfaces (active_device_id, name, interface_type, speed_label)
                         VALUES (:device_id, :name, :interface_type, :speed_label)',
                    );
                    $interfaceInsert->execute([
                        'device_id' => $deviceId,
                        'name' => $interfaceName,
                        'interface_type' => strtoupper(trim((string) $input['active_interface_type'])),
                        'speed_label' => trim((string) ($input['active_interface_speed'] ?? '')) ?: null,
                    ]);
                    $interfaceId = (int) $this->pdo->lastInsertId();
                }

                $frontConnection = $this->pdo->prepare(
                    'INSERT INTO patch_panel_front_connections (patch_panel_port_id, active_device_interface_id, patch_cord_label, notes)
                     VALUES (:port_id, :interface_id, :patch_cord_label, :notes)
                     ON DUPLICATE KEY UPDATE active_device_interface_id = VALUES(active_device_interface_id),
                        patch_cord_label = VALUES(patch_cord_label), notes = VALUES(notes)',
                );
                $frontConnection->execute([
                    'port_id' => $portId,
                    'interface_id' => $interfaceId,
                    'patch_cord_label' => trim((string) ($input['front_patch_cord_label'] ?? '')) ?: null,
                    'notes' => trim((string) ($input['front_connection_notes'] ?? '')) ?: null,
                ]);
            } elseif ($frontMode === 'PORT') {
                $destinationPortId = (int) ($input['front_destination_port_id'] ?? 0);
                $this->removeFrontPanelConnection($portId);
                $deleteDeviceConnection = $this->pdo->prepare('DELETE FROM patch_panel_front_connections WHERE patch_panel_port_id = :port_id');
                $deleteDeviceConnection->execute(['port_id' => $portId]);

                $destinationStatement = $this->pdo->prepare(
                    'SELECT ppp.id, ppp.administrative_status, pp.rack_id
                     FROM patch_panel_ports ppp
                     JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
                     WHERE ppp.id = :port_id AND pp.archived_at IS NULL
                     FOR UPDATE',
                );
                $destinationStatement->execute(['port_id' => $destinationPortId]);
                $destination = $destinationStatement->fetch();
                if (!is_array($destination)) {
                    throw new RuntimeException('Front destination port was not found');
                }
                if ((int) $destination['rack_id'] === (int) $port['rack_id']) {
                    throw new RuntimeException('Select a front port in another rack');
                }
                if (in_array($destination['administrative_status'], ['BLOCKED', 'DAMAGED'], true)) {
                    throw new RuntimeException('The selected front destination port is unavailable');
                }
                $frontOccupied = $this->pdo->prepare(
                    'SELECT
                        EXISTS (SELECT 1 FROM patch_panel_front_connections pfc WHERE pfc.patch_panel_port_id = :port_id_1)
                        OR EXISTS (
                            SELECT 1
                            FROM front_panel_connection_ports fpcp
                            JOIN front_panel_connections front_link ON front_link.id = fpcp.front_panel_connection_id
                            WHERE fpcp.patch_panel_port_id = :port_id_2
                              AND front_link.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                        )',
                );
                $frontOccupied->execute(['port_id_1' => $destinationPortId, 'port_id_2' => $destinationPortId]);
                if ((int) $frontOccupied->fetchColumn() === 1) {
                    throw new RuntimeException('The selected front destination port is already occupied');
                }

                $sequence = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM front_panel_connections')->fetchColumn();
                $code = sprintf('FPC-%06d', $sequence);
                $frontConnection = $this->pdo->prepare(
                    'INSERT INTO front_panel_connections (code, operational_status, patch_cord_label, notes)
                     VALUES (:code, "ACTIVE", :patch_cord_label, :notes)',
                );
                $frontConnection->execute([
                    'code' => $code,
                    'patch_cord_label' => trim((string) ($input['front_patch_cord_label'] ?? '')) ?: null,
                    'notes' => trim((string) ($input['front_connection_notes'] ?? '')) ?: null,
                ]);
                $frontConnectionId = (int) $this->pdo->lastInsertId();
                $frontEndpoint = $this->pdo->prepare(
                    'INSERT INTO front_panel_connection_ports (front_panel_connection_id, endpoint_side, patch_panel_port_id)
                     VALUES (:connection_id, :side, :port_id)',
                );
                $frontEndpoint->execute(['connection_id' => $frontConnectionId, 'side' => 'A', 'port_id' => $portId]);
                $frontEndpoint->execute(['connection_id' => $frontConnectionId, 'side' => 'Z', 'port_id' => $destinationPortId]);
            }

            $portRecord = [
                'id' => $portId,
                'connector_type_id' => $record['connector_type_id'],
                'label' => $record['label'],
                'administrative_status' => $record['status'],
                'notes' => $record['notes'],
                'highlight_color' => $record['highlight_color'],
                'front_connection_mode' => $frontMode,
                'rear_connection_mode' => $rearMode,
            ];
            $this->recordAudit('PATCH_PANEL_PORT', $portId, 'UPDATE', null, $portRecord);
            $this->pdo->commit();
            return $portRecord;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function removeRearFiberConnection(int $portId): void
    {
        $find = $this->pdo->prepare(
            'SELECT rfcp.rear_fiber_connection_id
             FROM rear_fiber_connection_ports rfcp
             JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
             WHERE rfcp.patch_panel_port_id = :port_id
               AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
             FOR UPDATE',
        );
        $find->execute(['port_id' => $portId]);
        $connectionIds = array_map('intval', $find->fetchAll(\PDO::FETCH_COLUMN));

        if ($connectionIds !== []) {
            $connectionPlaceholders = implode(',', array_fill(0, count($connectionIds), '?'));

            $strands = $this->pdo->prepare(
                "SELECT fiber_strand_id FROM rear_fiber_connection_segments WHERE rear_fiber_connection_id IN ($connectionPlaceholders)",
            );
            $strands->execute($connectionIds);
            $strandIds = array_map('intval', $strands->fetchAll(\PDO::FETCH_COLUMN));
            if ($strandIds !== []) {
                $strandPlaceholders = implode(',', array_fill(0, count($strandIds), '?'));
                $release = $this->pdo->prepare(
                    "UPDATE fiber_strands SET operational_status = 'AVAILABLE' WHERE id IN ($strandPlaceholders)",
                );
                $release->execute($strandIds);
            }

            $disconnect = $this->pdo->prepare(
                "UPDATE rear_fiber_connections SET operational_status = 'DISCONNECTED' WHERE id IN ($connectionPlaceholders)",
            );
            $disconnect->execute($connectionIds);
        }

        $legacy = $this->pdo->prepare(
            "UPDATE patch_cord_connections
             SET operational_status = 'DISCONNECTED'
             WHERE (a_port_id = :port_id_1 OR z_port_id = :port_id_2)
               AND operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')",
        );
        $legacy->execute(['port_id_1' => $portId, 'port_id_2' => $portId]);

        if ($connectionIds !== [] || $legacy->rowCount() > 0) {
            $this->recordAudit('PATCH_PANEL_PORT', $portId, 'DISCONNECT_REAR', null, null);
        }
    }

    public function rearFiberRoutes(int $portId): array
    {
        return array_map(static function (array $route): array {
            unset($route['segments']);
            return $route;
        }, $this->buildRearFiberRoutes($portId));
    }

    public function frontPortTargets(int $portId, string $query = ''): array
    {
        $sourceStatement = $this->pdo->prepare(
            'SELECT pp.rack_id
             FROM patch_panel_ports ppp
             JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
             WHERE ppp.id = :port_id AND pp.archived_at IS NULL',
        );
        $sourceStatement->execute(['port_id' => $portId]);
        $sourceRackId = $sourceStatement->fetchColumn();
        if ($sourceRackId === false) {
            return [];
        }

        $term = '%' . trim($query) . '%';
        $statement = $this->pdo->prepare(
            'SELECT ppp.id, ppp.port_number, ppp.label, ct.code AS connector,
                pp.id AS panel_id, pp.code AS panel_code, pp.name AS panel_name,
                r.id AS rack_id, r.code AS rack_code, r.name AS rack_name,
                sr.name AS room_name, l.name AS location_name
             FROM patch_panel_ports ppp
             JOIN connector_types ct ON ct.id = ppp.connector_type_id
             JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
             JOIN racks r ON r.id = pp.rack_id
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE ppp.id <> :source_port_id
               AND r.id <> :source_rack_id
               AND pp.archived_at IS NULL AND r.archived_at IS NULL
               AND ppp.administrative_status IN ("AVAILABLE", "RESERVED")
               AND NOT EXISTS (SELECT 1 FROM patch_panel_front_connections pfc WHERE pfc.patch_panel_port_id = ppp.id)
               AND (
                   NOT EXISTS (
                       SELECT 1
                       FROM front_panel_connection_ports occupied_endpoint
                       JOIN front_panel_connections occupied_link ON occupied_link.id = occupied_endpoint.front_panel_connection_id
                       WHERE occupied_endpoint.patch_panel_port_id = ppp.id
                         AND occupied_link.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM front_panel_connection_ports source_endpoint
                       JOIN front_panel_connections current_link ON current_link.id = source_endpoint.front_panel_connection_id
                       JOIN front_panel_connection_ports destination_endpoint
                         ON destination_endpoint.front_panel_connection_id = current_link.id
                         AND destination_endpoint.endpoint_side <> source_endpoint.endpoint_side
                       WHERE source_endpoint.patch_panel_port_id = :source_port_id_copy
                         AND destination_endpoint.patch_panel_port_id = ppp.id
                         AND current_link.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                   )
               )
               AND (:empty_query = 1 OR ppp.label LIKE :q1 OR pp.code LIKE :q2 OR pp.name LIKE :q3 OR r.code LIKE :q4 OR r.name LIKE :q5 OR sr.name LIKE :q6 OR l.name LIKE :q7)
             ORDER BY l.name, sr.name, r.code, pp.code, ppp.port_number
             LIMIT 160',
        );
        $statement->execute([
            'source_port_id' => $portId,
            'source_port_id_copy' => $portId,
            'source_rack_id' => (int) $sourceRackId,
            'empty_query' => trim($query) === '' ? 1 : 0,
            'q1' => $term,
            'q2' => $term,
            'q3' => $term,
            'q4' => $term,
            'q5' => $term,
            'q6' => $term,
            'q7' => $term,
        ]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'port_number' => (int) $row['port_number'],
            'label' => $row['label'],
            'connector' => $row['connector'],
            'panel_id' => (int) $row['panel_id'],
            'panel_code' => $row['panel_code'],
            'panel_name' => $row['panel_name'],
            'rack_id' => (int) $row['rack_id'],
            'rack' => $row['rack_code'],
            'rack_name' => $row['rack_name'],
            'room' => $row['room_name'],
            'location' => $row['location_name'],
        ], $statement->fetchAll());
    }

    public function connectionTargets(int $portId, string $query = '', string $routeKey = ''): array
    {
        $route = $this->findRearFiberRoute($portId, $routeKey);
        if ($route === null || !$route['selectable']) {
            return [];
        }

        $term = '%' . trim($query) . '%';
        $statement = $this->pdo->prepare(
            'SELECT ppp.id, ppp.port_number, ppp.label, ct.code AS connector,
                pp.id AS panel_id, pp.code AS panel_code, pp.name AS panel_name,
                r.code AS rack_code, sr.name AS room_name, l.name AS location_name
             FROM patch_panel_ports ppp
             JOIN connector_types ct ON ct.id = ppp.connector_type_id
             JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
             JOIN racks r ON r.id = pp.rack_id
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE ppp.id <> :port_id
               AND l.id = :destination_location_id
               AND (:destination_server_room_id = 0 OR sr.id = :destination_server_room_id_match)
               AND (:destination_rack_id = 0 OR r.id = :destination_rack_id_match)
               AND pp.archived_at IS NULL
               AND ppp.administrative_status IN ("AVAILABLE", "RESERVED")
               AND NOT EXISTS (SELECT 1 FROM fiber_connection_ports fcp WHERE fcp.patch_panel_port_id = ppp.id)
               AND NOT EXISTS (
                   SELECT 1 FROM patch_cord_connections pc
                   WHERE pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                     AND (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM rear_fiber_connection_ports rfcp
                   JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
                   WHERE rfcp.patch_panel_port_id = ppp.id
                     AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
               )
               AND (:empty_query = 1 OR ppp.label LIKE :q1 OR pp.code LIKE :q2 OR pp.name LIKE :q3 OR r.code LIKE :q4 OR sr.name LIKE :q5 OR l.name LIKE :q6)
             ORDER BY l.name, sr.name, r.code, pp.code, ppp.port_number
             LIMIT 100',
        );
        $statement->execute([
            'port_id' => $portId,
            'destination_location_id' => $route['destination_location_id'],
            'destination_server_room_id' => (int) ($route['destination_server_room_id'] ?? 0),
            'destination_server_room_id_match' => (int) ($route['destination_server_room_id'] ?? 0),
            'destination_rack_id' => (int) ($route['destination_rack_id'] ?? 0),
            'destination_rack_id_match' => (int) ($route['destination_rack_id'] ?? 0),
            'empty_query' => trim($query) === '' ? 1 : 0,
            'q1' => $term,
            'q2' => $term,
            'q3' => $term,
            'q4' => $term,
            'q5' => $term,
            'q6' => $term,
        ]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'panel_id' => (int) $row['panel_id'],
            'port_number' => (int) $row['port_number'],
            'label' => $row['label'],
            'connector' => $row['connector'],
            'panel_code' => $row['panel_code'],
            'panel_name' => $row['panel_name'],
            'rack' => $row['rack_code'],
            'room' => $row['room_name'],
            'location' => $row['location_name'],
        ], $statement->fetchAll());
    }

    public function connectPorts(int $sourcePortId, int $destinationPortId, array $input): array
    {
        $routeKey = trim((string) ($input['rear_route_key'] ?? ''));
        $this->pdo->beginTransaction();
        try {
            $ports = $this->pdo->prepare(
                'SELECT ppp.id, r.id AS rack_id, sr.id AS server_room_id, l.id AS location_id
                 FROM patch_panel_ports ppp
                 JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
                 JOIN racks r ON r.id = pp.rack_id
                 JOIN server_rooms sr ON sr.id = r.server_room_id
                 JOIN locations l ON l.id = sr.location_id
                 WHERE ppp.id IN (:source_id, :destination_id)
                 ORDER BY ppp.id FOR UPDATE',
            );
            $ports->execute(['source_id' => $sourcePortId, 'destination_id' => $destinationPortId]);
            $portRows = $ports->fetchAll();
            if (count($portRows) !== 2) {
                throw new RuntimeException('One or both ports were not found');
            }
            $contextByPort = array_column($portRows, null, 'id');
            $occupied = $this->pdo->prepare(
                'SELECT COUNT(*) FROM patch_panel_ports ppp
                 WHERE ppp.id IN (:source_id, :destination_id)
                   AND (
                       ppp.administrative_status IN ("BLOCKED", "DAMAGED")
                       OR EXISTS (SELECT 1 FROM fiber_connection_ports fcp WHERE fcp.patch_panel_port_id = ppp.id)
                       OR EXISTS (
                           SELECT 1 FROM patch_cord_connections pc
                           WHERE pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                             AND (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                       )
                       OR EXISTS (
                           SELECT 1
                           FROM rear_fiber_connection_ports rfcp
                           JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
                           WHERE rfcp.patch_panel_port_id = ppp.id
                             AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                       )
                   )',
            );
            $occupied->execute(['source_id' => $sourcePortId, 'destination_id' => $destinationPortId]);
            if ((int) $occupied->fetchColumn() > 0) {
                throw new RuntimeException('One of the selected rear ports is already occupied');
            }

            $route = $this->findRearFiberRoute($sourcePortId, $routeKey);
            if ($route === null || !$route['selectable']) {
                throw new RuntimeException('The selected physical fiber route is no longer available');
            }
            $destinationContext = $contextByPort[$destinationPortId] ?? null;
            $matchesLocation = is_array($destinationContext)
                && (int) $destinationContext['location_id'] === (int) $route['destination_location_id'];
            $matchesRoom = (int) ($route['destination_server_room_id'] ?? 0) === 0
                || (int) ($destinationContext['server_room_id'] ?? 0) === (int) $route['destination_server_room_id'];
            $matchesRack = (int) ($route['destination_rack_id'] ?? 0) === 0
                || (int) ($destinationContext['rack_id'] ?? 0) === (int) $route['destination_rack_id'];
            if (!$matchesLocation || !$matchesRoom || !$matchesRack) {
                throw new RuntimeException('The destination port is not located at the selected route endpoint');
            }

            $availableBySegment = [];
            $strandStatement = $this->pdo->prepare(
                'SELECT fs.id, fs.strand_number
                 FROM fiber_strands fs
                 WHERE fs.cable_segment_id = :segment_id
                   AND fs.operational_status = "AVAILABLE"
                   AND NOT EXISTS (SELECT 1 FROM rear_fiber_connection_segments rfcs WHERE rfcs.fiber_strand_id = fs.id)
                   AND NOT EXISTS (
                       SELECT 1
                       FROM fiber_ends fe
                       JOIN fiber_connection_ends fce ON fce.fiber_end_id = fe.id
                       WHERE fe.fiber_strand_id = fs.id
                   )
                 ORDER BY fs.strand_number
                 FOR UPDATE',
            );
            foreach ($route['segments'] as $segment) {
                $strandStatement->execute(['segment_id' => $segment['id']]);
                $available = $strandStatement->fetchAll();
                if ($available === []) {
                    throw new RuntimeException('The selected route has no free fiber on every segment');
                }
                $availableBySegment[(int) $segment['id']] = array_column($available, null, 'strand_number');
            }

            $commonNumbers = array_keys(reset($availableBySegment));
            foreach ($availableBySegment as $available) {
                $commonNumbers = array_values(array_intersect($commonNumbers, array_keys($available)));
            }
            sort($commonNumbers, SORT_NUMERIC);
            $commonNumber = $commonNumbers[0] ?? null;

            $sequence = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM rear_fiber_connections')->fetchColumn();
            $code = sprintf('RFC-%06d', $sequence);
            $connection = $this->pdo->prepare(
                'INSERT INTO rear_fiber_connections (code, operational_status, notes)
                 VALUES (:code, "ACTIVE", :notes)',
            );
            $connection->execute([
                'code' => $code,
                'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
            ]);
            $connectionId = (int) $this->pdo->lastInsertId();

            $endpoint = $this->pdo->prepare(
                'INSERT INTO rear_fiber_connection_ports (rear_fiber_connection_id, endpoint_side, patch_panel_port_id)
                 VALUES (:connection_id, :side, :port_id)',
            );
            $endpoint->execute(['connection_id' => $connectionId, 'side' => 'A', 'port_id' => $sourcePortId]);
            $endpoint->execute(['connection_id' => $connectionId, 'side' => 'Z', 'port_id' => $destinationPortId]);

            $allocation = $this->pdo->prepare(
                'INSERT INTO rear_fiber_connection_segments
                    (rear_fiber_connection_id, sequence_index, cable_segment_id, fiber_strand_id, direction)
                 VALUES (:connection_id, :sequence_index, :segment_id, :strand_id, :direction)',
            );
            $activateStrand = $this->pdo->prepare('UPDATE fiber_strands SET operational_status = "ACTIVE" WHERE id = :id');
            $allocatedFibers = [];
            foreach ($route['segments'] as $index => $segment) {
                $available = $availableBySegment[(int) $segment['id']];
                $selected = $commonNumber !== null ? $available[$commonNumber] : reset($available);
                $allocation->execute([
                    'connection_id' => $connectionId,
                    'sequence_index' => $index + 1,
                    'segment_id' => $segment['id'],
                    'strand_id' => $selected['id'],
                    'direction' => $segment['direction'],
                ]);
                $activateStrand->execute(['id' => $selected['id']]);
                $allocatedFibers[] = [
                    'segment_code' => $segment['segment_code'],
                    'strand_number' => (int) $selected['strand_number'],
                ];
            }

            $record = [
                'id' => $connectionId,
                'code' => $code,
                'source_port_id' => $sourcePortId,
                'destination_port_id' => $destinationPortId,
                'route_key' => $routeKey,
                'route_label' => $route['label'],
                'fibers' => $allocatedFibers,
                'status' => 'active',
            ];
            $this->recordAudit('PATCH_PANEL_PORT', $sourcePortId, 'CONNECT_REAR', null, $record);
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function createCable(array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $code = strtoupper(trim((string) $input['code']));
            $name = trim((string) $input['name']);
            $medium = strtoupper(trim((string) $input['medium']));
            $fiberCount = (int) $input['fiber_count'];
            $lengthMeters = (float) $input['length_m'];
            $status = strtoupper(trim((string) ($input['operational_status'] ?? 'PLANNED')));
            $status = in_array($status, ['PLANNED', 'ACTIVE', 'MAINTENANCE'], true) ? $status : 'PLANNED';
            $source = $this->cableEndpointNode($input, 'source');
            $destination = $this->cableEndpointNode($input, 'destination');

            $cableStatement = $this->pdo->prepare(
                'INSERT INTO cables (code, name, medium_type, declared_fiber_count, operational_status)
                 VALUES (:code, :name, :medium, :fiber_count, :status)',
            );
            $cableStatement->execute([
                'code' => $code,
                'name' => $name,
                'medium' => $medium,
                'fiber_count' => $fiberCount,
                'status' => $status,
            ]);
            $cableId = (int) $this->pdo->lastInsertId();

            $segmentCode = $code . '-S01';
            $segmentStatement = $this->pdo->prepare(
                'INSERT INTO cable_segments (cable_id, a_node_id, z_node_id, segment_code, fiber_count, length_m)
                 VALUES (:cable_id, :a_node_id, :z_node_id, :segment_code, :fiber_count, :length_m)',
            );
            $segmentStatement->execute([
                'cable_id' => $cableId,
                'a_node_id' => $source['node_id'],
                'z_node_id' => $destination['node_id'],
                'segment_code' => $segmentCode,
                'fiber_count' => $fiberCount,
                'length_m' => $lengthMeters,
            ]);
            $segmentId = (int) $this->pdo->lastInsertId();

            $strandStatement = $this->pdo->prepare(
                'INSERT INTO fiber_strands (cable_segment_id, strand_number, tube_number, tube_color, strand_color)
                 VALUES (:segment_id, :strand_number, :tube_number, :tube_color, :strand_color)',
            );
            $endStatement = $this->pdo->prepare(
                'INSERT INTO fiber_ends (fiber_strand_id, side) VALUES (:strand_id, :side)',
            );
            $colors = ['BLUE', 'ORANGE', 'GREEN', 'BROWN', 'SLATE', 'WHITE', 'RED', 'BLACK', 'YELLOW', 'VIOLET', 'ROSE', 'AQUA'];
            for ($strandNumber = 1; $strandNumber <= $fiberCount; $strandNumber++) {
                $tubeNumber = (int) ceil($strandNumber / 12);
                $strandStatement->execute([
                    'segment_id' => $segmentId,
                    'strand_number' => $strandNumber,
                    'tube_number' => $tubeNumber,
                    'tube_color' => $colors[($tubeNumber - 1) % count($colors)],
                    'strand_color' => $colors[($strandNumber - 1) % count($colors)],
                ]);
                $strandId = (int) $this->pdo->lastInsertId();
                $endStatement->execute(['strand_id' => $strandId, 'side' => 'A']);
                $endStatement->execute(['strand_id' => $strandId, 'side' => 'Z']);
            }

            $record = [
                'id' => $cableId,
                'code' => $code,
                'name' => $name,
                'medium' => $medium,
                'fiber_count' => $fiberCount,
                'used' => 0,
                'status' => strtolower($status),
                'source' => $source['label'],
                'destinations' => [$destination['label']],
                'source_endpoint_key' => $source['key'],
                'destination_endpoint_key' => $destination['key'],
                'length' => number_format($lengthMeters / 1000, 2) . ' km',
                'segments' => 1,
                'updated' => 'Now',
                'accent' => 'blue',
            ];
            $this->recordAudit('CABLE', $cableId, 'CREATE', null, $record);
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updateCable(int $cableId, array $input): array
    {
        $this->pdo->beginTransaction();
        try {
            $cableStatement = $this->pdo->prepare(
                'SELECT id, declared_fiber_count FROM cables WHERE id = :id AND archived_at IS NULL FOR UPDATE',
            );
            $cableStatement->execute(['id' => $cableId]);
            $cable = $cableStatement->fetch();
            if (!is_array($cable)) {
                throw new RuntimeException('Cable not found');
            }
            $segments = $this->pdo->prepare('SELECT id, fiber_count FROM cable_segments WHERE cable_id = :cable_id ORDER BY id');
            $segments->execute(['cable_id' => $cableId]);
            $segmentRows = $segments->fetchAll();
            $newFiberCount = (int) $input['fiber_count'];
            if ($newFiberCount !== (int) $cable['declared_fiber_count']) {
                if (count($segmentRows) !== 1) {
                    throw new RuntimeException('Branched cable capacity must be edited at segment level');
                }
                $this->resizeSingleSegment((int) $segmentRows[0]['id'], (int) $segmentRows[0]['fiber_count'], $newFiberCount);
            }
            $source = $this->cableEndpointNode($input, 'source');
            $destination = $this->cableEndpointNode($input, 'destination');
            $updateCable = $this->pdo->prepare(
                'UPDATE cables SET code = :code, name = :name, medium_type = :medium,
                    declared_fiber_count = :fiber_count, operational_status = :status WHERE id = :id',
            );
            $record = [
                'id' => $cableId,
                'code' => strtoupper(trim((string) $input['code'])),
                'name' => trim((string) $input['name']),
                'medium' => strtoupper(trim((string) $input['medium'])),
                'fiber_count' => $newFiberCount,
                'status' => strtoupper(trim((string) $input['operational_status'])),
            ];
            $updateCable->execute($record);
            $record['source_endpoint_key'] = $source['key'];
            $record['destination_endpoint_key'] = $destination['key'];
            if (count($segmentRows) === 1) {
                $updateSegment = $this->pdo->prepare(
                    'UPDATE cable_segments SET a_node_id = :a_node_id, z_node_id = :z_node_id, length_m = :length_m WHERE id = :id',
                );
                $updateSegment->execute([
                    'id' => $segmentRows[0]['id'],
                    'a_node_id' => $source['node_id'],
                    'z_node_id' => $destination['node_id'],
                    'length_m' => (float) $input['length_m'],
                ]);
            }
            $this->recordAudit('CABLE', $cableId, 'UPDATE', null, $record);
            $this->pdo->commit();
            return $record;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function archiveCable(int $cableId): array
    {
        $this->pdo->beginTransaction();
        try {
            $cableStatement = $this->pdo->prepare(
                'SELECT id, code, name FROM cables WHERE id = :id AND archived_at IS NULL FOR UPDATE',
            );
            $cableStatement->execute(['id' => $cableId]);
            $cable = $cableStatement->fetch();
            if (!is_array($cable)) {
                throw new RuntimeException('Cable not found');
            }

            $usageStatement = $this->pdo->prepare(
                'SELECT COUNT(DISTINCT fs.id)
                 FROM cable_segments cs
                 JOIN fiber_strands fs ON fs.cable_segment_id = cs.id
                 WHERE cs.cable_id = :id
                   AND (
                       fs.operational_status <> "AVAILABLE"
                       OR EXISTS (
                           SELECT 1 FROM fiber_ends fe
                           JOIN fiber_connection_ends fce ON fce.fiber_end_id = fe.id
                           WHERE fe.fiber_strand_id = fs.id
                       )
                       OR EXISTS (
                           SELECT 1 FROM rear_fiber_connection_segments rfcs
                           JOIN rear_fiber_connections rfc ON rfc.id = rfcs.rear_fiber_connection_id
                           WHERE rfcs.fiber_strand_id = fs.id
                             AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                       )
                   )',
            );
            $usageStatement->execute(['id' => $cableId]);
            if ((int) $usageStatement->fetchColumn() > 0) {
                throw new ResourceInUseException('cable_has_used_fibers');
            }

            $archive = $this->pdo->prepare(
                'UPDATE cables SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL',
            );
            $archive->execute(['id' => $cableId]);
            $this->recordArchiveAudit('CABLE', $cableId, $cable);
            $this->pdo->commit();
            return ['id' => $cableId, 'code' => $cable['code'], 'archived' => true];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function tracePort(int $portId): ?array
    {
        $sql = <<<'SQL'
WITH RECURSIVE
rear_ordered AS (
    SELECT rfcs.rear_fiber_connection_id, rfcs.sequence_index,
        ROW_NUMBER() OVER (PARTITION BY rfcs.rear_fiber_connection_id ORDER BY rfcs.sequence_index) AS route_position,
        COUNT(*) OVER (PARTITION BY rfcs.rear_fiber_connection_id) AS route_length,
        start_end.id AS start_end_id, finish_end.id AS finish_end_id
    FROM rear_fiber_connection_segments rfcs
    JOIN fiber_ends start_end
        ON start_end.fiber_strand_id = rfcs.fiber_strand_id
        AND start_end.side = IF(rfcs.direction = 'A_TO_Z', 'A', 'Z')
    JOIN fiber_ends finish_end
        ON finish_end.fiber_strand_id = rfcs.fiber_strand_id
        AND finish_end.side = IF(rfcs.direction = 'A_TO_Z', 'Z', 'A')
),
graph_edges AS (
    SELECT CONCAT('P:', fcp.patch_panel_port_id) AS source_key, CONCAT('E:', fce.fiber_end_id) AS target_key
    FROM fiber_connection_ports fcp
    JOIN fiber_connection_ends fce ON fce.connection_id = fcp.connection_id
    UNION ALL
    SELECT CONCAT('E:', fce.fiber_end_id), CONCAT('P:', fcp.patch_panel_port_id)
    FROM fiber_connection_ports fcp
    JOIN fiber_connection_ends fce ON fce.connection_id = fcp.connection_id
    UNION ALL
    SELECT CONCAT('E:', a.id), CONCAT('E:', z.id)
    FROM fiber_ends a
    JOIN fiber_ends z ON z.fiber_strand_id = a.fiber_strand_id AND z.side <> a.side
    UNION ALL
    SELECT CONCAT('E:', first_end.fiber_end_id), CONCAT('E:', second_end.fiber_end_id)
    FROM fiber_connection_ends first_end
    JOIN fiber_connection_ends second_end
        ON second_end.connection_id = first_end.connection_id
        AND second_end.fiber_end_id <> first_end.fiber_end_id
    UNION ALL
    SELECT CONCAT('P:', pc.a_port_id), CONCAT('P:', pc.z_port_id)
    FROM patch_cord_connections pc
    WHERE pc.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
    UNION ALL
    SELECT CONCAT('P:', pc.z_port_id), CONCAT('P:', pc.a_port_id)
    FROM patch_cord_connections pc
    WHERE pc.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
    UNION ALL
    SELECT CONCAT('P:', a_front.patch_panel_port_id), CONCAT('P:', z_front.patch_panel_port_id)
    FROM front_panel_connections front_link
    JOIN front_panel_connection_ports a_front ON a_front.front_panel_connection_id = front_link.id AND a_front.endpoint_side = 'A'
    JOIN front_panel_connection_ports z_front ON z_front.front_panel_connection_id = front_link.id AND z_front.endpoint_side = 'Z'
    WHERE front_link.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
    UNION ALL
    SELECT CONCAT('P:', z_front.patch_panel_port_id), CONCAT('P:', a_front.patch_panel_port_id)
    FROM front_panel_connections front_link
    JOIN front_panel_connection_ports a_front ON a_front.front_panel_connection_id = front_link.id AND a_front.endpoint_side = 'A'
    JOIN front_panel_connection_ports z_front ON z_front.front_panel_connection_id = front_link.id AND z_front.endpoint_side = 'Z'
    WHERE front_link.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
    UNION ALL
    SELECT CONCAT('P:', rfcp.patch_panel_port_id), CONCAT('E:', ro.start_end_id)
    FROM rear_fiber_connection_ports rfcp
    JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
    JOIN rear_ordered ro ON ro.rear_fiber_connection_id = rfc.id AND ro.route_position = 1
    WHERE rfcp.endpoint_side = 'A' AND rfc.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
    UNION ALL
    SELECT CONCAT('E:', ro.start_end_id), CONCAT('P:', rfcp.patch_panel_port_id)
    FROM rear_fiber_connection_ports rfcp
    JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
    JOIN rear_ordered ro ON ro.rear_fiber_connection_id = rfc.id AND ro.route_position = 1
    WHERE rfcp.endpoint_side = 'A' AND rfc.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
    UNION ALL
    SELECT CONCAT('P:', rfcp.patch_panel_port_id), CONCAT('E:', ro.finish_end_id)
    FROM rear_fiber_connection_ports rfcp
    JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
    JOIN rear_ordered ro ON ro.rear_fiber_connection_id = rfc.id AND ro.route_position = ro.route_length
    WHERE rfcp.endpoint_side = 'Z' AND rfc.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
    UNION ALL
    SELECT CONCAT('E:', ro.finish_end_id), CONCAT('P:', rfcp.patch_panel_port_id)
    FROM rear_fiber_connection_ports rfcp
    JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
    JOIN rear_ordered ro ON ro.rear_fiber_connection_id = rfc.id AND ro.route_position = ro.route_length
    WHERE rfcp.endpoint_side = 'Z' AND rfc.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
    UNION ALL
    SELECT CONCAT('E:', current_segment.finish_end_id), CONCAT('E:', next_segment.start_end_id)
    FROM rear_ordered current_segment
    JOIN rear_ordered next_segment
        ON next_segment.rear_fiber_connection_id = current_segment.rear_fiber_connection_id
        AND next_segment.route_position = current_segment.route_position + 1
    UNION ALL
    SELECT CONCAT('E:', next_segment.start_end_id), CONCAT('E:', current_segment.finish_end_id)
    FROM rear_ordered current_segment
    JOIN rear_ordered next_segment
        ON next_segment.rear_fiber_connection_id = current_segment.rear_fiber_connection_id
        AND next_segment.route_position = current_segment.route_position + 1
),
fiber_walk (node_key, depth, visited) AS (
    SELECT CONCAT('P:', :port_id), 0, CAST(CONCAT('|P:', :port_id_copy, '|') AS CHAR(4000))
    UNION ALL
    SELECT edge.target_key, fiber_walk.depth + 1, CONCAT(fiber_walk.visited, edge.target_key, '|')
    FROM fiber_walk
    JOIN graph_edges edge ON edge.source_key = fiber_walk.node_key
    WHERE fiber_walk.visited NOT LIKE CONCAT('%|', edge.target_key, '|%')
      AND fiber_walk.depth < 255
)
SELECT node_key, MIN(depth) AS depth
FROM fiber_walk
GROUP BY node_key
ORDER BY depth
SQL;
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['port_id' => $portId, 'port_id_copy' => $portId]);
        $nodes = $statement->fetchAll();
        if (count($nodes) <= 1) {
            return null;
        }

        $steps = [];
        foreach ($nodes as $node) {
            [$type, $rawId] = explode(':', $node['node_key'], 2);
            $step = $type === 'P'
                ? $this->describePortStep((int) $rawId, (int) $node['depth'])
                : $this->describeFiberEndStep((int) $rawId);
            if ($step !== null) {
                $steps[] = $step;
            }
        }

        return [
            'source_port_id' => $portId,
            'status' => 'complete',
            'total_loss' => null,
            'steps' => $steps,
        ];
    }

    private function findRearFiberRoute(int $portId, string $routeKey): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $routeKey)) {
            return null;
        }
        foreach ($this->buildRearFiberRoutes($portId) as $route) {
            if (hash_equals($route['key'], $routeKey)) {
                return $route;
            }
        }
        return null;
    }

    private function removeFrontPanelConnection(int $portId): void
    {
        $find = $this->pdo->prepare(
            'SELECT front_panel_connection_id
             FROM front_panel_connection_ports
             WHERE patch_panel_port_id = :port_id
             FOR UPDATE',
        );
        $find->execute(['port_id' => $portId]);
        $connectionId = $find->fetchColumn();
        if ($connectionId === false) {
            return;
        }
        $deleteEndpoints = $this->pdo->prepare('DELETE FROM front_panel_connection_ports WHERE front_panel_connection_id = :connection_id');
        $deleteEndpoints->execute(['connection_id' => (int) $connectionId]);
        $deleteConnection = $this->pdo->prepare('DELETE FROM front_panel_connections WHERE id = :connection_id');
        $deleteConnection->execute(['connection_id' => (int) $connectionId]);
    }

    private function buildRearFiberRoutes(int $portId): array
    {
        $sourceStatement = $this->pdo->prepare(
            'SELECT ppp.id, ppp.administrative_status, r.id AS rack_id, sr.id AS server_room_id,
                l.id AS location_id, l.code AS location_code, l.name AS location_name,
                CASE
                    WHEN ppp.administrative_status IN ("BLOCKED", "DAMAGED")
                      OR EXISTS (SELECT 1 FROM fiber_connection_ports fcp WHERE fcp.patch_panel_port_id = ppp.id)
                      OR EXISTS (
                          SELECT 1 FROM patch_cord_connections pc
                          WHERE pc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                            AND (pc.a_port_id = ppp.id OR pc.z_port_id = ppp.id)
                      )
                      OR EXISTS (
                          SELECT 1
                          FROM rear_fiber_connection_ports rfcp
                          JOIN rear_fiber_connections rfc ON rfc.id = rfcp.rear_fiber_connection_id
                          WHERE rfcp.patch_panel_port_id = ppp.id
                            AND rfc.operational_status IN ("PLANNED", "ACTIVE", "DAMAGED")
                      ) THEN 1 ELSE 0
                END AS rear_occupied
             FROM patch_panel_ports ppp
             JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
             JOIN racks r ON r.id = pp.rack_id
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE ppp.id = :port_id AND pp.archived_at IS NULL AND l.archived_at IS NULL',
        );
        $sourceStatement->execute(['port_id' => $portId]);
        $source = $sourceStatement->fetch();
        if (!is_array($source) || (int) $source['rear_occupied'] === 1) {
            return [];
        }

        $segmentRows = $this->pdo->query(
            'SELECT cs.id, cs.segment_code, cs.a_node_id, cs.z_node_id, cs.fiber_count, COALESCE(cs.length_m, 0) AS length_m,
                c.id AS cable_id, c.code AS cable_code, c.name AS cable_name, c.medium_type, c.operational_status,
                an.location_id AS a_location_id, an.server_room_id AS a_server_room_id, an.rack_id AS a_rack_id,
                al.code AS a_location_code, al.name AS a_location_name, a_room.name AS a_room_name, a_rack.code AS a_rack_code,
                zn.location_id AS z_location_id, zn.server_room_id AS z_server_room_id, zn.rack_id AS z_rack_id,
                zl.code AS z_location_code, zl.name AS z_location_name, z_room.name AS z_room_name, z_rack.code AS z_rack_code,
                (SELECT COUNT(*)
                 FROM fiber_strands fs
                 WHERE fs.cable_segment_id = cs.id
                   AND fs.operational_status = "AVAILABLE"
                   AND NOT EXISTS (SELECT 1 FROM rear_fiber_connection_segments rfcs WHERE rfcs.fiber_strand_id = fs.id)
                   AND NOT EXISTS (
                       SELECT 1
                       FROM fiber_ends fe
                       JOIN fiber_connection_ends fce ON fce.fiber_end_id = fe.id
                       WHERE fe.fiber_strand_id = fs.id
                   )) AS free_fibers
             FROM cable_segments cs
             JOIN cables c ON c.id = cs.cable_id
             JOIN fiber_nodes an ON an.id = cs.a_node_id
             LEFT JOIN locations al ON al.id = an.location_id
             LEFT JOIN server_rooms a_room ON a_room.id = an.server_room_id
             LEFT JOIN racks a_rack ON a_rack.id = an.rack_id
             JOIN fiber_nodes zn ON zn.id = cs.z_node_id
             LEFT JOIN locations zl ON zl.id = zn.location_id
             LEFT JOIN server_rooms z_room ON z_room.id = zn.server_room_id
             LEFT JOIN racks z_rack ON z_rack.id = zn.rack_id
             WHERE c.archived_at IS NULL
               AND c.operational_status <> "RETIRED"
             ORDER BY c.code, cs.segment_code',
        )->fetchAll();
        if ($segmentRows === []) {
            return [];
        }

        $adjacency = [];
        $nodeScopes = [];
        foreach ($segmentRows as $row) {
            $segment = [
                'id' => (int) $row['id'],
                'segment_code' => $row['segment_code'],
                'cable_id' => (int) $row['cable_id'],
                'cable_code' => $row['cable_code'],
                'cable_name' => $row['cable_name'],
                'medium' => $row['medium_type'],
                'operational_status' => $row['operational_status'],
                'fiber_count' => (int) $row['fiber_count'],
                'free_fibers' => (int) $row['free_fibers'],
                'length_m' => (float) $row['length_m'],
            ];
            $aNodeId = (int) $row['a_node_id'];
            $zNodeId = (int) $row['z_node_id'];
            $adjacency[$aNodeId][] = $segment + ['from_node_id' => $aNodeId, 'to_node_id' => $zNodeId, 'direction' => 'A_TO_Z'];
            $adjacency[$zNodeId][] = $segment + ['from_node_id' => $zNodeId, 'to_node_id' => $aNodeId, 'direction' => 'Z_TO_A'];
            if ($row['a_location_id'] !== null) {
                $nodeScopes[$aNodeId] = [
                    'id' => (int) $row['a_location_id'],
                    'location_id' => (int) $row['a_location_id'],
                    'server_room_id' => $row['a_server_room_id'] !== null ? (int) $row['a_server_room_id'] : null,
                    'rack_id' => $row['a_rack_id'] !== null ? (int) $row['a_rack_id'] : null,
                    'code' => $row['a_location_code'],
                    'name' => $row['a_location_name'],
                    'label' => $row['a_rack_id'] !== null
                        ? $row['a_location_code'] . ' · ' . $row['a_room_name'] . ' · ' . $row['a_rack_code']
                        : ($row['a_server_room_id'] !== null
                            ? $row['a_location_code'] . ' · ' . $row['a_room_name']
                            : $row['a_location_code'] . ' · ' . $row['a_location_name']),
                ];
            }
            if ($row['z_location_id'] !== null) {
                $nodeScopes[$zNodeId] = [
                    'id' => (int) $row['z_location_id'],
                    'location_id' => (int) $row['z_location_id'],
                    'server_room_id' => $row['z_server_room_id'] !== null ? (int) $row['z_server_room_id'] : null,
                    'rack_id' => $row['z_rack_id'] !== null ? (int) $row['z_rack_id'] : null,
                    'code' => $row['z_location_code'],
                    'name' => $row['z_location_name'],
                    'label' => $row['z_rack_id'] !== null
                        ? $row['z_location_code'] . ' · ' . $row['z_room_name'] . ' · ' . $row['z_rack_code']
                        : ($row['z_server_room_id'] !== null
                            ? $row['z_location_code'] . ' · ' . $row['z_room_name']
                            : $row['z_location_code'] . ' · ' . $row['z_location_name']),
                ];
            }
        }

        $sourceLocationId = (int) $source['location_id'];
        $sourceRoomId = (int) $source['server_room_id'];
        $sourceRackId = (int) $source['rack_id'];
        $startNodes = [];
        foreach ($nodeScopes as $nodeId => $scope) {
            $matchesLocation = (int) $scope['location_id'] === $sourceLocationId;
            $matchesRoom = $scope['server_room_id'] === null || (int) $scope['server_room_id'] === $sourceRoomId;
            $matchesRack = $scope['rack_id'] === null || (int) $scope['rack_id'] === $sourceRackId;
            if ($matchesLocation && $matchesRoom && $matchesRack) {
                $startNodes[] = (int) $nodeId;
            }
        }
        if ($startNodes === []) {
            return [];
        }

        $routes = [];
        $routeSignatures = [];
        $appendRoute = static function (array $path, array $destination) use ($portId, $sourceLocationId, &$routes, &$routeSignatures): void {
            if (count(array_unique(array_column($path, 'cable_id'))) !== 1) {
                return;
            }
            $signature = $sourceLocationId . '|' . $destination['id'] . '|' . implode(',', array_map(
                static fn (array $segment): string => $segment['id'] . ':' . $segment['direction'],
                $path,
            ));
            if (isset($routeSignatures[$signature])) {
                return;
            }
            $routeSignatures[$signature] = true;

            $capacity = min(array_column($path, 'fiber_count'));
            $freeFibers = min(array_column($path, 'free_fibers'));
            $mediums = array_values(array_unique(array_column($path, 'medium')));
            $statuses = array_values(array_unique(array_column($path, 'operational_status')));
            $cableCodes = [];
            foreach (array_column($path, 'cable_code') as $cableCode) {
                if ($cableCodes === [] || end($cableCodes) !== $cableCode) {
                    $cableCodes[] = $cableCode;
                }
            }
            $selectable = $freeFibers > 0 && count($mediums) === 1 && $statuses === ['ACTIVE'];
            $availability = 'available';
            if (count($mediums) > 1) {
                $availability = 'mixed_medium';
            } elseif (in_array('DAMAGED', $statuses, true)) {
                $availability = 'damaged';
            } elseif (in_array('MAINTENANCE', $statuses, true)) {
                $availability = 'maintenance';
            } elseif (in_array('PLANNED', $statuses, true)) {
                $availability = 'planned';
            } elseif ($freeFibers <= 0) {
                $availability = 'full';
            }
            $lengthMeters = array_sum(array_column($path, 'length_m'));
            $routeKey = hash('sha256', $portId . '|' . $signature);
            $routes[] = [
                'key' => $routeKey,
                'destination_location_id' => (int) $destination['id'],
                'destination_location_code' => $destination['code'],
                'destination_location_name' => $destination['name'],
                'destination_server_room_id' => $destination['server_room_id'],
                'destination_rack_id' => $destination['rack_id'],
                'destination_label' => $destination['label'],
                'cable_codes' => $cableCodes,
                'cable_path' => implode(' → ', $cableCodes),
                'medium' => count($mediums) === 1 ? $mediums[0] : implode('/', $mediums),
                'fiber_capacity' => $capacity,
                'free_fibers' => $freeFibers,
                'used_fibers' => max(0, $capacity - $freeFibers),
                'segment_count' => count($path),
                'length_m' => $lengthMeters,
                'availability' => $availability,
                'selectable' => $selectable,
                'label' => implode(' → ', $cableCodes) . ' · ' . $destination['label'],
                'segments' => $path,
            ];
        };

        $walk = function (int $nodeId, int $originNodeId, array $path, array $visitedNodes, array $visitedSegments) use (&$walk, &$routes, $nodeScopes, $adjacency, $appendRoute): void {
            if (count($routes) >= 120 || count($path) >= 10) {
                return;
            }
            $scope = $nodeScopes[$nodeId] ?? null;
            if ($path !== [] && $nodeId !== $originNodeId && $scope !== null) {
                $appendRoute($path, $scope);
            }
            foreach ($adjacency[$nodeId] ?? [] as $segment) {
                $segmentId = (int) $segment['id'];
                $nextNodeId = (int) $segment['to_node_id'];
                if ($path !== [] && (int) $segment['cable_id'] !== (int) $path[0]['cable_id']) {
                    continue;
                }
                if (isset($visitedSegments[$segmentId]) || isset($visitedNodes[$nextNodeId])) {
                    continue;
                }
                $nextVisitedNodes = $visitedNodes;
                $nextVisitedNodes[$nextNodeId] = true;
                $nextVisitedSegments = $visitedSegments;
                $nextVisitedSegments[$segmentId] = true;
                $walk($nextNodeId, $originNodeId, [...$path, $segment], $nextVisitedNodes, $nextVisitedSegments);
            }
        };
        foreach ($startNodes as $startNodeId) {
            $walk($startNodeId, $startNodeId, [], [$startNodeId => true], []);
        }

        usort($routes, static fn (array $left, array $right): int =>
            ($right['selectable'] <=> $left['selectable'])
            ?: strcmp($left['destination_location_name'], $right['destination_location_name'])
            ?: strcmp($left['cable_path'], $right['cable_path'])
            ?: ($left['segment_count'] <=> $right['segment_count'])
        );
        return $routes;
    }

    private function upsDevice(int $upsDeviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, server_room_id, code, name, manufacturer, model, serial_number,
                rated_power_va, rated_power_w, ip_address, management_url, battery_replaced_at,
                battery_replacement_interval_months, battery_count, battery_type,
                operational_status, notes, created_at, updated_at
             FROM ups_devices
             WHERE id = :id AND archived_at IS NULL',
        );
        $statement->execute(['id' => $upsDeviceId]);
        $upsDevice = $statement->fetch();
        return is_array($upsDevice) ? $this->normalizeUpsDevice($upsDevice) : null;
    }

    private function upsDeviceRecord(array $input): array
    {
        return [
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
            'operational_status' => strtoupper(trim((string) ($input['operational_status'] ?? 'ACTIVE'))),
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ];
    }

    private function normalizeUpsDevice(array $upsDevice): array
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

        return [
            'id' => (int) $upsDevice['id'],
            'server_room_id' => (int) $upsDevice['server_room_id'],
            'code' => $upsDevice['code'],
            'name' => $upsDevice['name'],
            'manufacturer' => $upsDevice['manufacturer'],
            'model' => $upsDevice['model'],
            'serial_number' => $upsDevice['serial_number'],
            'rated_power_va' => $upsDevice['rated_power_va'] === null ? null : (int) $upsDevice['rated_power_va'],
            'rated_power_w' => $upsDevice['rated_power_w'] === null ? null : (int) $upsDevice['rated_power_w'],
            'ip_address' => $upsDevice['ip_address'],
            'management_url' => $upsDevice['management_url'],
            'battery_replaced_at' => $upsDevice['battery_replaced_at'],
            'battery_replacement_interval_months' => (int) $upsDevice['battery_replacement_interval_months'],
            'battery_count' => ($upsDevice['battery_count'] ?? null) === null ? null : (int) $upsDevice['battery_count'],
            'battery_type' => $upsDevice['battery_type'] ?? null,
            'battery_due_at' => $batteryDueAt,
            'battery_state' => $batteryState,
            'operational_status' => strtolower((string) $upsDevice['operational_status']),
            'notes' => $upsDevice['notes'],
            'created_at' => $upsDevice['created_at'] ?? null,
            'updated_at' => $upsDevice['updated_at'] ?? null,
        ];
    }

    private function archiveContainer(
        string $table,
        string $entityType,
        int $id,
        string $fiberNodeScope,
        array $dependencyChecks,
    ): array {
        $allowedTables = ['locations', 'server_rooms', 'racks'];
        if (!in_array($table, $allowedTables, true)) {
            throw new RuntimeException('Unsupported archive entity type');
        }

        $this->pdo->beginTransaction();
        try {
            $recordStatement = $this->pdo->prepare(
                sprintf('SELECT id, code, name FROM %s WHERE id = :id AND archived_at IS NULL FOR UPDATE', $table),
            );
            $recordStatement->execute(['id' => $id]);
            $record = $recordStatement->fetch();
            if (!is_array($record)) {
                throw new RuntimeException('Infrastructure element not found');
            }

            foreach ($dependencyChecks as [$query, $reason]) {
                $dependencyStatement = $this->pdo->prepare($query);
                $dependencyStatement->execute(['id' => $id]);
                if ((int) $dependencyStatement->fetchColumn() > 0) {
                    throw new ResourceInUseException($reason);
                }
            }

            $archiveNodes = $this->pdo->prepare(
                sprintf(
                    'UPDATE fiber_nodes SET archived_at = CURRENT_TIMESTAMP WHERE %s AND archived_at IS NULL',
                    $fiberNodeScope,
                ),
            );
            $archiveNodes->execute(['id' => $id]);
            $archiveRecord = $this->pdo->prepare(
                sprintf('UPDATE %s SET archived_at = CURRENT_TIMESTAMP WHERE id = :id AND archived_at IS NULL', $table),
            );
            $archiveRecord->execute(['id' => $id]);
            $this->recordArchiveAudit($entityType, $id, $record);
            $this->pdo->commit();
            return ['id' => $id, 'code' => $record['code'], 'archived' => true];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function recordArchiveAudit(string $entityType, int $entityId, array $record): void
    {
        $this->recordAudit($entityType, $entityId, 'ARCHIVE', $record, null);
    }

    private function recordAudit(string $entityType, int $entityId, string $action, ?array $before, ?array $after): void
    {
        $audit = $this->pdo->prepare(
            'INSERT INTO audit_events (user_id, entity_type, entity_id, action, before_data, after_data, ip_address)
             VALUES (:user_id, :entity_type, :entity_id, :action, :before_data, :after_data, :ip_address)',
        );
        $audit->execute([
            'user_id' => $_SESSION['user_id'] ?? null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_data' => $before !== null ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_data' => $after !== null ? json_encode($after, JSON_THROW_ON_ERROR) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    private function findLocation(int $id): ?array
    {
        foreach ($this->locations() as $location) {
            if ((int) $location['id'] === $id) {
                return $location;
            }
        }
        return null;
    }

    private function assetImages(string $entityType, int $entityId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, entity_type, entity_id, storage_path, original_name, mime_type, size_bytes, width_px, height_px, created_at
             FROM asset_images
             WHERE entity_type = :entity_type AND entity_id = :entity_id
             ORDER BY created_at DESC, id DESC',
        );
        $statement->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);
        return array_map(fn (array $image): array => $this->normalizeAssetImage($image), $statement->fetchAll());
    }

    private function normalizeAssetImage(array $image): array
    {
        return [
            'id' => (int) $image['id'],
            'entity_type' => $image['entity_type'],
            'entity_id' => (int) $image['entity_id'],
            'storage_path' => $image['storage_path'],
            'original_name' => $image['original_name'],
            'mime_type' => $image['mime_type'],
            'size_bytes' => (int) $image['size_bytes'],
            'width_px' => (int) $image['width_px'],
            'height_px' => (int) $image['height_px'],
            'created_at' => $image['created_at'],
            'url' => '/media/assets/' . $image['id'],
        ];
    }

    public function serverRoomExists(int $id): bool
    {
        return $this->recordExists('server_rooms', $id);
    }

    public function cableExists(int $id): bool
    {
        return $this->recordExists('cables', $id);
    }

    private function recordExists(string $table, int $id): bool
    {
        $allowedTables = ['locations', 'server_rooms', 'racks', 'cables', 'active_devices', 'rack_items'];
        if (!in_array($table, $allowedTables, true)) {
            throw new RuntimeException('Unsupported record type');
        }
        $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE id = :id AND archived_at IS NULL', $table));
        $statement->execute(['id' => $id]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function resizeSingleSegment(int $segmentId, int $currentCount, int $newCount): void
    {
        if ($newCount < $currentCount) {
            $connected = $this->pdo->prepare(
                'SELECT COUNT(*) FROM fiber_strands fs
                 WHERE fs.cable_segment_id = :segment_id
                   AND fs.strand_number > :new_count
                   AND (
                       EXISTS (
                           SELECT 1
                           FROM fiber_ends fe
                           JOIN fiber_connection_ends fce ON fce.fiber_end_id = fe.id
                           WHERE fe.fiber_strand_id = fs.id
                       )
                       OR EXISTS (SELECT 1 FROM rear_fiber_connection_segments rfcs WHERE rfcs.fiber_strand_id = fs.id)
                   )',
            );
            $connected->execute(['segment_id' => $segmentId, 'new_count' => $newCount]);
            if ((int) $connected->fetchColumn() > 0) {
                throw new RuntimeException('Connected fibers must be disconnected before reducing cable capacity');
            }
            $deleteEnds = $this->pdo->prepare(
                'DELETE fe FROM fiber_ends fe JOIN fiber_strands fs ON fs.id = fe.fiber_strand_id
                 WHERE fs.cable_segment_id = :segment_id AND fs.strand_number > :new_count',
            );
            $deleteEnds->execute(['segment_id' => $segmentId, 'new_count' => $newCount]);
            $deleteStrands = $this->pdo->prepare(
                'DELETE FROM fiber_strands WHERE cable_segment_id = :segment_id AND strand_number > :new_count',
            );
            $deleteStrands->execute(['segment_id' => $segmentId, 'new_count' => $newCount]);
        } elseif ($newCount > $currentCount) {
            $insertStrand = $this->pdo->prepare(
                'INSERT INTO fiber_strands (cable_segment_id, strand_number, tube_number, tube_color, strand_color)
                 VALUES (:segment_id, :strand_number, :tube_number, :tube_color, :strand_color)',
            );
            $insertEnd = $this->pdo->prepare('INSERT INTO fiber_ends (fiber_strand_id, side) VALUES (:strand_id, :side)');
            $colors = ['BLUE', 'ORANGE', 'GREEN', 'BROWN', 'SLATE', 'WHITE', 'RED', 'BLACK', 'YELLOW', 'VIOLET', 'ROSE', 'AQUA'];
            for ($strandNumber = $currentCount + 1; $strandNumber <= $newCount; $strandNumber++) {
                $tubeNumber = (int) ceil($strandNumber / 12);
                $insertStrand->execute([
                    'segment_id' => $segmentId,
                    'strand_number' => $strandNumber,
                    'tube_number' => $tubeNumber,
                    'tube_color' => $colors[($tubeNumber - 1) % count($colors)],
                    'strand_color' => $colors[($strandNumber - 1) % count($colors)],
                ]);
                $strandId = (int) $this->pdo->lastInsertId();
                $insertEnd->execute(['strand_id' => $strandId, 'side' => 'A']);
                $insertEnd->execute(['strand_id' => $strandId, 'side' => 'Z']);
            }
        }
        $updateSegment = $this->pdo->prepare('UPDATE cable_segments SET fiber_count = :fiber_count WHERE id = :id');
        $updateSegment->execute(['id' => $segmentId, 'fiber_count' => $newCount]);
    }

    private function cableEndpointNode(array $input, string $side): array
    {
        $legacyLocationId = (int) ($input[$side . '_location_id'] ?? 0);
        $key = strtoupper(trim((string) ($input[$side . '_endpoint'] ?? '')));
        if ($key === '' && $legacyLocationId > 0) {
            $key = 'LOCATION:' . $legacyLocationId;
        }
        if (!preg_match('/^(LOCATION|ROOM|RACK):([1-9][0-9]*)$/', $key, $matches)) {
            throw new RuntimeException('Invalid cable endpoint');
        }
        $id = (int) $matches[2];

        return match ($matches[1]) {
            'LOCATION' => $this->buildingEntryNode($id),
            'ROOM' => $this->serverRoomEntryNode($id),
            'RACK' => $this->rackEntryNode($id),
        };
    }

    private function buildingEntryNode(int $locationId): array
    {
        $location = $this->pdo->prepare(
            'SELECT id, code, name FROM locations WHERE id = :id AND archived_at IS NULL',
        );
        $location->execute(['id' => $locationId]);
        $locationRow = $location->fetch();
        if (!is_array($locationRow)) {
            throw new \RuntimeException('Location not found');
        }

        $existing = $this->pdo->prepare(
            'SELECT id FROM fiber_nodes
             WHERE location_id = :location_id AND node_type = "BUILDING_ENTRY" AND archived_at IS NULL
             ORDER BY id LIMIT 1',
        );
        $existing->execute(['location_id' => $locationId]);
        $nodeId = $existing->fetchColumn();
        if ($nodeId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO fiber_nodes (location_id, node_type, code, name)
                 VALUES (:location_id, "BUILDING_ENTRY", :code, :name)',
            );
            $insert->execute([
                'location_id' => $locationId,
                'code' => substr('ENTRY-' . $locationRow['code'], 0, 60),
                'name' => $locationRow['name'] . ' building entry',
            ]);
            $nodeId = (int) $this->pdo->lastInsertId();
        }

        return [
            'node_id' => (int) $nodeId,
            'key' => 'LOCATION:' . $locationRow['id'],
            'location_id' => (int) $locationRow['id'],
            'server_room_id' => null,
            'rack_id' => null,
            'location_name' => $locationRow['name'],
            'label' => $locationRow['code'] . ' · ' . $locationRow['name'],
        ];
    }

    private function serverRoomEntryNode(int $serverRoomId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sr.id, sr.code, sr.name, l.id AS location_id, l.code AS location_code, l.name AS location_name
             FROM server_rooms sr
             JOIN locations l ON l.id = sr.location_id
             WHERE sr.id = :id AND sr.archived_at IS NULL AND l.archived_at IS NULL',
        );
        $statement->execute(['id' => $serverRoomId]);
        $room = $statement->fetch();
        if (!is_array($room)) {
            throw new RuntimeException('Server room not found');
        }

        $existing = $this->pdo->prepare(
            'SELECT id FROM fiber_nodes
             WHERE server_room_id = :server_room_id AND rack_id IS NULL
               AND node_type = "BUILDING_ENTRY" AND archived_at IS NULL
             ORDER BY id LIMIT 1',
        );
        $existing->execute(['server_room_id' => $serverRoomId]);
        $nodeId = $existing->fetchColumn();
        if ($nodeId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO fiber_nodes (location_id, server_room_id, node_type, code, name)
                 VALUES (:location_id, :server_room_id, "BUILDING_ENTRY", :code, :name)',
            );
            $insert->execute([
                'location_id' => $room['location_id'],
                'server_room_id' => $serverRoomId,
                'code' => 'ENTRY-ROOM-' . $serverRoomId,
                'name' => $room['name'] . ' cable entry',
            ]);
            $nodeId = (int) $this->pdo->lastInsertId();
        }

        return [
            'node_id' => (int) $nodeId,
            'key' => 'ROOM:' . $room['id'],
            'location_id' => (int) $room['location_id'],
            'server_room_id' => (int) $room['id'],
            'rack_id' => null,
            'location_name' => $room['location_name'],
            'label' => $room['location_code'] . ' · ' . $room['name'],
        ];
    }

    private function rackEntryNode(int $rackId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.code, r.name, sr.id AS server_room_id, sr.name AS room_name,
                l.id AS location_id, l.code AS location_code, l.name AS location_name
             FROM racks r
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE r.id = :id AND r.archived_at IS NULL AND sr.archived_at IS NULL AND l.archived_at IS NULL',
        );
        $statement->execute(['id' => $rackId]);
        $rack = $statement->fetch();
        if (!is_array($rack)) {
            throw new RuntimeException('Rack not found');
        }

        $existing = $this->pdo->prepare(
            'SELECT id FROM fiber_nodes
             WHERE rack_id = :rack_id AND node_type = "BUILDING_ENTRY" AND archived_at IS NULL
             ORDER BY id LIMIT 1',
        );
        $existing->execute(['rack_id' => $rackId]);
        $nodeId = $existing->fetchColumn();
        if ($nodeId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO fiber_nodes (location_id, server_room_id, rack_id, node_type, code, name)
                 VALUES (:location_id, :server_room_id, :rack_id, "BUILDING_ENTRY", :code, :name)',
            );
            $insert->execute([
                'location_id' => $rack['location_id'],
                'server_room_id' => $rack['server_room_id'],
                'rack_id' => $rackId,
                'code' => 'ENTRY-RACK-' . $rackId,
                'name' => $rack['code'] . ' rack cable entry',
            ]);
            $nodeId = (int) $this->pdo->lastInsertId();
        }

        return [
            'node_id' => (int) $nodeId,
            'key' => 'RACK:' . $rack['id'],
            'location_id' => (int) $rack['location_id'],
            'server_room_id' => (int) $rack['server_room_id'],
            'rack_id' => (int) $rack['id'],
            'location_name' => $rack['location_name'],
            'label' => $rack['location_code'] . ' · ' . $rack['room_name'] . ' · ' . $rack['code'],
        ];
    }

    private function alerts(int $openEnds, int $damaged): array
    {
        $alerts = [];
        if ($openEnds > 0) {
            $alerts[] = ['severity' => 'warning', 'title' => $openEnds . ' unterminated fiber ends', 'detail' => 'Network-wide inventory', 'time' => 'Live'];
        }
        if ($damaged > 0) {
            $alerts[] = ['severity' => 'danger', 'title' => $damaged . ' damaged fibers', 'detail' => 'Service review required', 'time' => 'Live'];
        }
        if ($alerts === []) {
            $alerts[] = ['severity' => 'success', 'title' => 'No active infrastructure alerts', 'detail' => 'All monitored assets are healthy', 'time' => 'Live'];
        }
        return $alerts;
    }

    private function recentActivity(): array
    {
        $rows = $this->pdo->query(
            'SELECT action, entity_type, created_at FROM audit_events ORDER BY created_at DESC LIMIT 3',
        )->fetchAll();
        if ($rows === []) {
            return [['initials' => 'NS', 'action' => 'Inventory is ready', 'time' => 'Now', 'tone' => 'violet']];
        }

        return array_map(static fn (array $row): array => [
            'initials' => 'NS',
            'action' => strtolower($row['action']) . ' ' . strtolower($row['entity_type']),
            'time' => $row['created_at'],
            'tone' => 'violet',
        ], $rows);
    }

    private function describePortStep(int $portId, int $depth): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ppp.port_number, pp.code AS panel_code, r.code AS rack_code, l.name AS location_name
             FROM patch_panel_ports ppp
             JOIN patch_panels pp ON pp.id = ppp.patch_panel_id
             JOIN racks r ON r.id = pp.rack_id
             JOIN server_rooms sr ON sr.id = r.server_room_id
             JOIN locations l ON l.id = sr.location_id
             WHERE ppp.id = :id',
        );
        $statement->execute(['id' => $portId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'type' => 'port',
            'label' => sprintf('%s · Port %02d', $row['panel_code'], $row['port_number']),
            'detail' => $row['location_name'] . ' · ' . $row['rack_code'],
            'status' => $depth === 0 ? 'start' : 'destination',
        ];
    }

    private function describeFiberEndStep(int $fiberEndId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT fe.side, fs.strand_number, fs.strand_color, fs.operational_status,
                cs.segment_code, c.code AS cable_code, c.medium_type,
                CASE WHEN fe.side = "A" THEN an.name ELSE zn.name END AS node_name
             FROM fiber_ends fe
             JOIN fiber_strands fs ON fs.id = fe.fiber_strand_id
             JOIN cable_segments cs ON cs.id = fs.cable_segment_id
             JOIN cables c ON c.id = cs.cable_id
             JOIN fiber_nodes an ON an.id = cs.a_node_id
             JOIN fiber_nodes zn ON zn.id = cs.z_node_id
             WHERE fe.id = :id',
        );
        $statement->execute(['id' => $fiberEndId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'type' => 'fiber',
            'label' => sprintf('%s · Fiber %02d', $row['segment_code'], $row['strand_number']),
            'detail' => sprintf('%s · %s · Side %s · %s', $row['cable_code'], $row['medium_type'], $row['side'], $row['node_name']),
            'status' => strtolower($row['operational_status']),
        ];
    }
}
