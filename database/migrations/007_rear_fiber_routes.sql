CREATE TABLE rear_fiber_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    operational_status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    notes TEXT NULL,
    connected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_rear_fiber_connections_status CHECK (operational_status IN ('PLANNED', 'ACTIVE', 'DISCONNECTED', 'DAMAGED')),
    CONSTRAINT uq_rear_fiber_connections_code UNIQUE (code),
    INDEX idx_rear_fiber_connections_status (operational_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rear_fiber_connection_ports (
    rear_fiber_connection_id BIGINT UNSIGNED NOT NULL,
    endpoint_side CHAR(1) NOT NULL,
    patch_panel_port_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (rear_fiber_connection_id, endpoint_side),
    CONSTRAINT chk_rear_fiber_connection_ports_side CHECK (endpoint_side IN ('A', 'Z')),
    CONSTRAINT fk_rear_fiber_connection_ports_connection FOREIGN KEY (rear_fiber_connection_id) REFERENCES rear_fiber_connections(id),
    CONSTRAINT fk_rear_fiber_connection_ports_port FOREIGN KEY (patch_panel_port_id) REFERENCES patch_panel_ports(id),
    CONSTRAINT uq_rear_fiber_connection_ports_port UNIQUE (patch_panel_port_id),
    INDEX idx_rear_fiber_connection_ports_port (patch_panel_port_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rear_fiber_connection_segments (
    rear_fiber_connection_id BIGINT UNSIGNED NOT NULL,
    sequence_index SMALLINT UNSIGNED NOT NULL,
    cable_segment_id BIGINT UNSIGNED NOT NULL,
    fiber_strand_id BIGINT UNSIGNED NOT NULL,
    direction VARCHAR(8) NOT NULL,
    PRIMARY KEY (rear_fiber_connection_id, sequence_index),
    CONSTRAINT chk_rear_fiber_connection_segments_direction CHECK (direction IN ('A_TO_Z', 'Z_TO_A')),
    CONSTRAINT fk_rear_fiber_connection_segments_connection FOREIGN KEY (rear_fiber_connection_id) REFERENCES rear_fiber_connections(id),
    CONSTRAINT fk_rear_fiber_connection_segments_segment FOREIGN KEY (cable_segment_id) REFERENCES cable_segments(id),
    CONSTRAINT fk_rear_fiber_connection_segments_strand FOREIGN KEY (fiber_strand_id) REFERENCES fiber_strands(id),
    CONSTRAINT uq_rear_fiber_connection_segments_strand UNIQUE (fiber_strand_id),
    CONSTRAINT uq_rear_fiber_connection_segments_route_segment UNIQUE (rear_fiber_connection_id, cable_segment_id),
    INDEX idx_rear_fiber_connection_segments_segment (cable_segment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW patch_panel_utilization AS
SELECT
    pp.id AS patch_panel_id,
    pp.port_count,
    COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL OR rfc.id IS NOT NULL OR pfc.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) AS occupied_ports,
    pp.port_count - COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL OR rfc.id IS NOT NULL OR pfc.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) AS available_ports,
    ROUND(COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL OR rfc.id IS NOT NULL OR pfc.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) * 100.0 / pp.port_count, 1) AS utilization_percent
FROM patch_panels pp
LEFT JOIN patch_panel_ports ppp ON ppp.patch_panel_id = pp.id
LEFT JOIN fiber_connection_ports fcp ON fcp.patch_panel_port_id = ppp.id
LEFT JOIN patch_cord_connections pca
    ON pca.a_port_id = ppp.id AND pca.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
LEFT JOIN patch_cord_connections pcz
    ON pcz.z_port_id = ppp.id AND pcz.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
LEFT JOIN rear_fiber_connection_ports rfcp ON rfcp.patch_panel_port_id = ppp.id
LEFT JOIN rear_fiber_connections rfc
    ON rfc.id = rfcp.rear_fiber_connection_id AND rfc.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
LEFT JOIN patch_panel_front_connections pfc ON pfc.patch_panel_port_id = ppp.id
GROUP BY pp.id, pp.port_count;
