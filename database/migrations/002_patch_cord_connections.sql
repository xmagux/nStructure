CREATE TABLE patch_cord_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    a_port_id BIGINT UNSIGNED NOT NULL,
    z_port_id BIGINT UNSIGNED NOT NULL,
    fiber_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    operational_status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    notes TEXT NULL,
    connected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_patch_cords_distinct_ports CHECK (a_port_id <> z_port_id),
    CONSTRAINT chk_patch_cords_fiber_count CHECK (fiber_count BETWEEN 1 AND 24),
    CONSTRAINT chk_patch_cords_status CHECK (operational_status IN ('PLANNED', 'ACTIVE', 'DISCONNECTED', 'DAMAGED')),
    CONSTRAINT fk_patch_cords_a_port FOREIGN KEY (a_port_id) REFERENCES patch_panel_ports (id),
    CONSTRAINT fk_patch_cords_z_port FOREIGN KEY (z_port_id) REFERENCES patch_panel_ports (id),
    CONSTRAINT uq_patch_cords_code UNIQUE (code),
    INDEX idx_patch_cords_a_port (a_port_id),
    INDEX idx_patch_cords_z_port (z_port_id),
    INDEX idx_patch_cords_status (operational_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW patch_panel_utilization AS
SELECT
    pp.id AS patch_panel_id,
    pp.port_count,
    COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) AS occupied_ports,
    pp.port_count - COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) AS available_ports,
    ROUND(COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) * 100.0 / pp.port_count, 1) AS utilization_percent
FROM patch_panels pp
LEFT JOIN patch_panel_ports ppp ON ppp.patch_panel_id = pp.id
LEFT JOIN fiber_connection_ports fcp ON fcp.patch_panel_port_id = ppp.id
LEFT JOIN patch_cord_connections pca
    ON pca.a_port_id = ppp.id AND pca.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
LEFT JOIN patch_cord_connections pcz
    ON pcz.z_port_id = ppp.id AND pcz.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
GROUP BY pp.id, pp.port_count;
