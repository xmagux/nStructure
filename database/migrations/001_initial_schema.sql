CREATE TABLE locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    address VARCHAR(255) NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    notes TEXT NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_locations_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE server_rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    floor VARCHAR(40) NULL,
    notes TEXT NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_server_rooms_location FOREIGN KEY (location_id) REFERENCES locations (id),
    CONSTRAINT uq_server_rooms_location_code UNIQUE (location_id, code),
    INDEX idx_server_rooms_location (location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE racks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    server_room_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(160) NOT NULL,
    total_units SMALLINT UNSIGNED NOT NULL DEFAULT 42,
    row_label VARCHAR(40) NULL,
    position_index SMALLINT UNSIGNED NULL,
    notes TEXT NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_racks_total_units CHECK (total_units BETWEEN 1 AND 60),
    CONSTRAINT fk_racks_server_room FOREIGN KEY (server_room_id) REFERENCES server_rooms (id),
    CONSTRAINT uq_racks_room_code UNIQUE (server_room_id, code),
    INDEX idx_racks_server_room (server_room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fiber_nodes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id BIGINT UNSIGNED NULL,
    server_room_id BIGINT UNSIGNED NULL,
    node_type VARCHAR(32) NOT NULL,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    notes TEXT NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_fiber_nodes_type CHECK (node_type IN ('PATCH_PANEL', 'SPLICE_CLOSURE', 'BUILDING_ENTRY', 'HANDHOLE', 'LOOSE_END')),
    CONSTRAINT fk_fiber_nodes_location FOREIGN KEY (location_id) REFERENCES locations (id),
    CONSTRAINT fk_fiber_nodes_server_room FOREIGN KEY (server_room_id) REFERENCES server_rooms (id),
    CONSTRAINT uq_fiber_nodes_code UNIQUE (code),
    INDEX idx_fiber_nodes_location (location_id),
    INDEX idx_fiber_nodes_room (server_room_id),
    INDEX idx_fiber_nodes_type (node_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE connector_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(24) NOT NULL,
    translation_key VARCHAR(120) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT uq_connector_types_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE patch_panels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rack_id BIGINT UNSIGNED NOT NULL,
    fiber_node_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    rack_unit_start SMALLINT UNSIGNED NOT NULL,
    rack_unit_height SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    port_count SMALLINT UNSIGNED NOT NULL,
    layout_rows SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    layout_columns SMALLINT UNSIGNED NOT NULL,
    manufacturer VARCHAR(120) NULL,
    model VARCHAR(120) NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_patch_panels_port_count CHECK (port_count BETWEEN 1 AND 288),
    CONSTRAINT chk_patch_panels_layout CHECK (layout_rows * layout_columns >= port_count),
    CONSTRAINT fk_patch_panels_rack FOREIGN KEY (rack_id) REFERENCES racks (id),
    CONSTRAINT fk_patch_panels_fiber_node FOREIGN KEY (fiber_node_id) REFERENCES fiber_nodes (id),
    CONSTRAINT uq_patch_panels_code UNIQUE (code),
    CONSTRAINT uq_patch_panels_fiber_node UNIQUE (fiber_node_id),
    INDEX idx_patch_panels_rack (rack_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE patch_panel_ports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patch_panel_id BIGINT UNSIGNED NOT NULL,
    connector_type_id BIGINT UNSIGNED NOT NULL,
    port_number SMALLINT UNSIGNED NOT NULL,
    layout_row SMALLINT UNSIGNED NOT NULL,
    layout_column SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(120) NULL,
    administrative_status VARCHAR(24) NOT NULL DEFAULT 'AVAILABLE',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_patch_panel_ports_status CHECK (administrative_status IN ('AVAILABLE', 'RESERVED', 'BLOCKED', 'DAMAGED')),
    CONSTRAINT fk_patch_panel_ports_panel FOREIGN KEY (patch_panel_id) REFERENCES patch_panels (id),
    CONSTRAINT fk_patch_panel_ports_connector FOREIGN KEY (connector_type_id) REFERENCES connector_types (id),
    CONSTRAINT uq_patch_panel_ports_number UNIQUE (patch_panel_id, port_number),
    CONSTRAINT uq_patch_panel_ports_layout UNIQUE (patch_panel_id, layout_row, layout_column),
    INDEX idx_patch_panel_ports_panel (patch_panel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE splice_closures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fiber_node_id BIGINT UNSIGNED NOT NULL,
    closure_type VARCHAR(80) NULL,
    manufacturer VARCHAR(120) NULL,
    model VARCHAR(120) NULL,
    tray_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_splice_closures_node FOREIGN KEY (fiber_node_id) REFERENCES fiber_nodes (id),
    CONSTRAINT uq_splice_closures_node UNIQUE (fiber_node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cables (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    medium_type VARCHAR(8) NOT NULL,
    declared_fiber_count SMALLINT UNSIGNED NOT NULL,
    manufacturer VARCHAR(120) NULL,
    cable_type VARCHAR(120) NULL,
    owner VARCHAR(120) NULL,
    operational_status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    notes TEXT NULL,
    installed_at DATE NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_cables_medium CHECK (medium_type IN ('SM', 'MM')),
    CONSTRAINT chk_cables_fiber_count CHECK (declared_fiber_count BETWEEN 1 AND 1728),
    CONSTRAINT chk_cables_status CHECK (operational_status IN ('PLANNED', 'ACTIVE', 'MAINTENANCE', 'DAMAGED', 'RETIRED')),
    CONSTRAINT uq_cables_code UNIQUE (code),
    INDEX idx_cables_status (operational_status),
    INDEX idx_cables_medium (medium_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cable_segments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cable_id BIGINT UNSIGNED NOT NULL,
    a_node_id BIGINT UNSIGNED NOT NULL,
    z_node_id BIGINT UNSIGNED NOT NULL,
    segment_code VARCHAR(60) NOT NULL,
    fiber_count SMALLINT UNSIGNED NOT NULL,
    length_m DECIMAL(12, 2) NULL,
    installation_type VARCHAR(32) NULL,
    route_data JSON NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_cable_segments_distinct_nodes CHECK (a_node_id <> z_node_id),
    CONSTRAINT chk_cable_segments_fiber_count CHECK (fiber_count BETWEEN 1 AND 1728),
    CONSTRAINT fk_cable_segments_cable FOREIGN KEY (cable_id) REFERENCES cables (id),
    CONSTRAINT fk_cable_segments_a_node FOREIGN KEY (a_node_id) REFERENCES fiber_nodes (id),
    CONSTRAINT fk_cable_segments_z_node FOREIGN KEY (z_node_id) REFERENCES fiber_nodes (id),
    CONSTRAINT uq_cable_segments_code UNIQUE (cable_id, segment_code),
    INDEX idx_cable_segments_cable (cable_id),
    INDEX idx_cable_segments_a_node (a_node_id),
    INDEX idx_cable_segments_z_node (z_node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fiber_strands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cable_segment_id BIGINT UNSIGNED NOT NULL,
    strand_number SMALLINT UNSIGNED NOT NULL,
    tube_number SMALLINT UNSIGNED NULL,
    tube_color VARCHAR(32) NULL,
    strand_color VARCHAR(32) NULL,
    operational_status VARCHAR(24) NOT NULL DEFAULT 'AVAILABLE',
    label VARCHAR(120) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_fiber_strands_status CHECK (operational_status IN ('AVAILABLE', 'ACTIVE', 'RESERVED', 'DAMAGED', 'UNKNOWN')),
    CONSTRAINT fk_fiber_strands_segment FOREIGN KEY (cable_segment_id) REFERENCES cable_segments (id),
    CONSTRAINT uq_fiber_strands_number UNIQUE (cable_segment_id, strand_number),
    INDEX idx_fiber_strands_segment (cable_segment_id),
    INDEX idx_fiber_strands_status (operational_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fiber_ends (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fiber_strand_id BIGINT UNSIGNED NOT NULL,
    side CHAR(1) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_fiber_ends_side CHECK (side IN ('A', 'Z')),
    CONSTRAINT fk_fiber_ends_strand FOREIGN KEY (fiber_strand_id) REFERENCES fiber_strands (id),
    CONSTRAINT uq_fiber_ends_side UNIQUE (fiber_strand_id, side),
    INDEX idx_fiber_ends_strand (fiber_strand_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fiber_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fiber_node_id BIGINT UNSIGNED NOT NULL,
    connection_type VARCHAR(24) NOT NULL,
    measured_loss_db DECIMAL(7, 3) NULL,
    connected_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_fiber_connections_type CHECK (connection_type IN ('SPLICE', 'TERMINATION', 'PASS_THROUGH', 'SPLITTER')),
    CONSTRAINT fk_fiber_connections_node FOREIGN KEY (fiber_node_id) REFERENCES fiber_nodes (id),
    INDEX idx_fiber_connections_node (fiber_node_id),
    INDEX idx_fiber_connections_type (connection_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fiber_connection_ends (
    connection_id BIGINT UNSIGNED NOT NULL,
    fiber_end_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'MEMBER',
    PRIMARY KEY (connection_id, fiber_end_id),
    CONSTRAINT fk_fiber_connection_ends_connection FOREIGN KEY (connection_id) REFERENCES fiber_connections (id),
    CONSTRAINT fk_fiber_connection_ends_end FOREIGN KEY (fiber_end_id) REFERENCES fiber_ends (id),
    CONSTRAINT uq_fiber_connection_ends_end UNIQUE (fiber_end_id),
    INDEX idx_fiber_connection_ends_connection (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fiber_connection_ports (
    connection_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    patch_panel_port_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_fiber_connection_ports_connection FOREIGN KEY (connection_id) REFERENCES fiber_connections (id),
    CONSTRAINT fk_fiber_connection_ports_port FOREIGN KEY (patch_panel_port_id) REFERENCES patch_panel_ports (id),
    CONSTRAINT uq_fiber_connection_ports_port UNIQUE (patch_panel_port_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE app_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(24) NOT NULL DEFAULT 'VIEWER',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_app_users_role CHECK (role IN ('ADMIN', 'EDITOR', 'VIEWER')),
    CONSTRAINT uq_app_users_email UNIQUE (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    before_data JSON NULL,
    after_data JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_events_user FOREIGN KEY (user_id) REFERENCES app_users (id),
    INDEX idx_audit_events_entity (entity_type, entity_id),
    INDEX idx_audit_events_user (user_id),
    INDEX idx_audit_events_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE VIEW patch_panel_utilization AS
SELECT
    pp.id AS patch_panel_id,
    pp.port_count,
    COUNT(fcp.patch_panel_port_id) AS occupied_ports,
    pp.port_count - COUNT(fcp.patch_panel_port_id) AS available_ports,
    ROUND(COUNT(fcp.patch_panel_port_id) * 100.0 / pp.port_count, 1) AS utilization_percent
FROM patch_panels pp
LEFT JOIN patch_panel_ports ppp ON ppp.patch_panel_id = pp.id
LEFT JOIN fiber_connection_ports fcp ON fcp.patch_panel_port_id = ppp.id
GROUP BY pp.id, pp.port_count;
