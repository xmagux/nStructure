INSERT INTO connector_types (code, translation_key) VALUES
    ('E2000', 'connector.e2000'),
    ('SC-PC', 'connector.sc_pc'),
    ('SC-APC', 'connector.sc_apc'),
    ('LC', 'connector.lc');

INSERT INTO locations (code, name, address, latitude, longitude) VALUES
    ('WAW-DC1', 'Warsaw Core', 'Kasprzaka 18, Warsaw', 52.2298000, 20.9729000),
    ('WAW-NORTH', 'North Office', 'Modlinska 61, Warsaw', 52.2866000, 21.0032000),
    ('WAW-LAB', 'Research Campus', 'Zwirki i Wigury 101, Warsaw', 52.1657000, 20.9671000);

INSERT INTO server_rooms (location_id, code, name, floor) VALUES
    (1, 'SR-A', 'Core Room A', '-1'),
    (2, 'SR-B', 'Distribution Room B', '1'),
    (3, 'SR-C', 'Laboratory Room C', '0');

INSERT INTO racks (server_room_id, code, name, total_units, row_label, position_index) VALUES
    (1, 'R01', 'Core Rack 01', 42, 'A', 1),
    (2, 'R08', 'Distribution Rack 08', 42, 'B', 8),
    (3, 'R03', 'Laboratory Rack 03', 42, 'A', 3);

INSERT INTO fiber_nodes (location_id, server_room_id, node_type, code, name, latitude, longitude) VALUES
    (1, 1, 'PATCH_PANEL', 'NODE-PP-WAW-01', 'Core Rack Fiber Panel', 52.2298000, 20.9729000),
    (2, 2, 'PATCH_PANEL', 'NODE-PP-NORTH-01', 'North Distribution Panel', 52.2866000, 21.0032000),
    (3, 3, 'PATCH_PANEL', 'NODE-PP-LAB-01', 'Research Campus Panel', 52.1657000, 20.9671000),
    (NULL, NULL, 'SPLICE_CLOSURE', 'NODE-SC-M01', 'Metro Splice M01', 52.2398000, 20.9900000);

INSERT INTO patch_panels (
    rack_id, fiber_node_id, code, name, rack_unit_start, rack_unit_height,
    port_count, layout_rows, layout_columns, manufacturer, model
) VALUES
    (1, 1, 'PP-WAW-01', 'Core Fiber Panel', 40, 2, 48, 2, 24, 'Fibrain', 'FDP-48-LC'),
    (2, 2, 'PP-NORTH-01', 'North Distribution Panel', 39, 1, 24, 1, 24, 'Fibrain', 'FDP-24-SC'),
    (3, 3, 'PP-LAB-01', 'Research Laboratory Panel', 38, 1, 24, 1, 24, 'Fibrain', 'FDP-24-SC');

INSERT INTO patch_panel_ports (
    patch_panel_id, connector_type_id, port_number, layout_row, layout_column, label
)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 48
)
SELECT 1, 4, n, CEIL(n / 24), ((n - 1) MOD 24) + 1, CONCAT('CORE-', LPAD(n, 2, '0')) FROM seq;

INSERT INTO patch_panel_ports (
    patch_panel_id, connector_type_id, port_number, layout_row, layout_column, label
)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 24
)
SELECT 2, 3, n, 1, n, CONCAT('NORTH-', LPAD(n, 2, '0')) FROM seq;

INSERT INTO patch_panel_ports (
    patch_panel_id, connector_type_id, port_number, layout_row, layout_column, label
)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 24
)
SELECT 3, 1, n, 1, n, CONCAT('LAB-', LPAD(n, 2, '0')) FROM seq;

INSERT INTO splice_closures (fiber_node_id, closure_type, manufacturer, model, tray_count, notes)
VALUES (4, 'DOME', 'FOSC', 'D5', 4, 'Main branch between Warsaw Core, North Office, and Research Campus.');

INSERT INTO cables (
    code, name, medium_type, declared_fiber_count, manufacturer, cable_type,
    owner, operational_status, installed_at
) VALUES
    ('CBL-WAW-001', 'Warsaw Metro Branch', 'SM', 48, 'Corning', 'OS2 outdoor', 'Network Operations', 'ACTIVE', '2025-04-11');

INSERT INTO cable_segments (
    cable_id, a_node_id, z_node_id, segment_code, fiber_count, length_m, installation_type
) VALUES
    (1, 1, 4, 'SEG-A-M01', 48, 1840.00, 'DUCT'),
    (1, 4, 2, 'SEG-M01-B', 24, 2260.00, 'DUCT'),
    (1, 4, 3, 'SEG-M01-C', 24, 3120.00, 'DUCT');

INSERT INTO fiber_strands (
    cable_segment_id, strand_number, tube_number, tube_color, strand_color
)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 48
)
SELECT 1, n, CEIL(n / 12),
    ELT(CEIL(n / 12), 'BLUE', 'ORANGE', 'GREEN', 'BROWN'),
    ELT(((n - 1) MOD 12) + 1, 'BLUE', 'ORANGE', 'GREEN', 'BROWN', 'SLATE', 'WHITE', 'RED', 'BLACK', 'YELLOW', 'VIOLET', 'ROSE', 'AQUA')
FROM seq;

INSERT INTO fiber_strands (
    cable_segment_id, strand_number, tube_number, tube_color, strand_color
)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 24
)
SELECT 2, n, CEIL(n / 12),
    ELT(CEIL(n / 12), 'BLUE', 'ORANGE'),
    ELT(((n - 1) MOD 12) + 1, 'BLUE', 'ORANGE', 'GREEN', 'BROWN', 'SLATE', 'WHITE', 'RED', 'BLACK', 'YELLOW', 'VIOLET', 'ROSE', 'AQUA')
FROM seq;

INSERT INTO fiber_strands (
    cable_segment_id, strand_number, tube_number, tube_color, strand_color
)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 24
)
SELECT 3, n, CEIL(n / 12),
    ELT(CEIL(n / 12), 'BLUE', 'ORANGE'),
    ELT(((n - 1) MOD 12) + 1, 'BLUE', 'ORANGE', 'GREEN', 'BROWN', 'SLATE', 'WHITE', 'RED', 'BLACK', 'YELLOW', 'VIOLET', 'ROSE', 'AQUA')
FROM seq;

INSERT INTO fiber_ends (fiber_strand_id, side)
SELECT id, 'A' FROM fiber_strands
UNION ALL
SELECT id, 'Z' FROM fiber_strands;

INSERT INTO fiber_connections (fiber_node_id, connection_type, connected_at, notes)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 48
)
SELECT 4, 'SPLICE', CURRENT_TIMESTAMP,
    IF(n <= 24, CONCAT('BRANCH-B-', n), CONCAT('BRANCH-C-', n))
FROM seq;

INSERT INTO fiber_connection_ends (connection_id, fiber_end_id, role)
SELECT fc.id, fe.id, 'IN'
FROM fiber_connections fc
JOIN fiber_strands fs ON fs.cable_segment_id = 1
    AND fs.strand_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED)
JOIN fiber_ends fe ON fe.fiber_strand_id = fs.id AND fe.side = 'Z'
WHERE fc.fiber_node_id = 4 AND fc.connection_type = 'SPLICE';

INSERT INTO fiber_connection_ends (connection_id, fiber_end_id, role)
SELECT fc.id, fe.id, 'OUT'
FROM fiber_connections fc
JOIN fiber_strands fs ON fs.cable_segment_id = 2
    AND fs.strand_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED)
JOIN fiber_ends fe ON fe.fiber_strand_id = fs.id AND fe.side = 'A'
WHERE fc.notes LIKE 'BRANCH-B-%';

INSERT INTO fiber_connection_ends (connection_id, fiber_end_id, role)
SELECT fc.id, fe.id, 'OUT'
FROM fiber_connections fc
JOIN fiber_strands fs ON fs.cable_segment_id = 3
    AND fs.strand_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED) - 24
JOIN fiber_ends fe ON fe.fiber_strand_id = fs.id AND fe.side = 'A'
WHERE fc.notes LIKE 'BRANCH-C-%';

INSERT INTO fiber_connections (fiber_node_id, connection_type, connected_at, notes)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 48
)
SELECT 1, 'TERMINATION', CURRENT_TIMESTAMP, CONCAT('TERM-A-', n) FROM seq;

INSERT INTO fiber_connections (fiber_node_id, connection_type, connected_at, notes)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 24
)
SELECT 2, 'TERMINATION', CURRENT_TIMESTAMP, CONCAT('TERM-B-', n) FROM seq;

INSERT INTO fiber_connections (fiber_node_id, connection_type, connected_at, notes)
WITH RECURSIVE seq(n) AS (
    SELECT 1
    UNION ALL
    SELECT n + 1 FROM seq WHERE n < 12
)
SELECT 3, 'TERMINATION', CURRENT_TIMESTAMP, CONCAT('TERM-C-', n) FROM seq;

INSERT INTO fiber_connection_ends (connection_id, fiber_end_id, role)
SELECT fc.id, fe.id, 'MEMBER'
FROM fiber_connections fc
JOIN fiber_strands fs ON fs.cable_segment_id = 1
    AND fs.strand_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED)
JOIN fiber_ends fe ON fe.fiber_strand_id = fs.id AND fe.side = 'A'
WHERE fc.notes LIKE 'TERM-A-%';

INSERT INTO fiber_connection_ends (connection_id, fiber_end_id, role)
SELECT fc.id, fe.id, 'MEMBER'
FROM fiber_connections fc
JOIN fiber_strands fs ON fs.cable_segment_id = 2
    AND fs.strand_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED)
JOIN fiber_ends fe ON fe.fiber_strand_id = fs.id AND fe.side = 'Z'
WHERE fc.notes LIKE 'TERM-B-%';

INSERT INTO fiber_connection_ends (connection_id, fiber_end_id, role)
SELECT fc.id, fe.id, 'MEMBER'
FROM fiber_connections fc
JOIN fiber_strands fs ON fs.cable_segment_id = 3
    AND fs.strand_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED)
JOIN fiber_ends fe ON fe.fiber_strand_id = fs.id AND fe.side = 'Z'
WHERE fc.notes LIKE 'TERM-C-%';

INSERT INTO fiber_connection_ports (connection_id, patch_panel_port_id)
SELECT fc.id, ppp.id
FROM fiber_connections fc
JOIN patch_panel_ports ppp ON ppp.patch_panel_id = 1
    AND ppp.port_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED)
WHERE fc.notes LIKE 'TERM-A-%';

INSERT INTO fiber_connection_ports (connection_id, patch_panel_port_id)
SELECT fc.id, ppp.id
FROM fiber_connections fc
JOIN patch_panel_ports ppp ON ppp.patch_panel_id = 2
    AND ppp.port_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED)
WHERE fc.notes LIKE 'TERM-B-%';

INSERT INTO fiber_connection_ports (connection_id, patch_panel_port_id)
SELECT fc.id, ppp.id
FROM fiber_connections fc
JOIN patch_panel_ports ppp ON ppp.patch_panel_id = 3
    AND ppp.port_number = CAST(SUBSTRING_INDEX(fc.notes, '-', -1) AS UNSIGNED)
WHERE fc.notes LIKE 'TERM-C-%';

UPDATE fiber_strands fs
SET fs.operational_status = 'ACTIVE'
WHERE EXISTS (
    SELECT 1
    FROM fiber_ends fe
    JOIN fiber_connection_ends fce ON fce.fiber_end_id = fe.id
    WHERE fe.fiber_strand_id = fs.id
);
