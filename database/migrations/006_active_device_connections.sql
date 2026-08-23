CREATE TABLE active_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rack_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    device_type VARCHAR(24) NOT NULL,
    vendor VARCHAR(120) NOT NULL,
    model VARCHAR(120) NULL,
    management_address VARCHAR(255) NULL,
    notes TEXT NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_active_devices_type CHECK (device_type IN ('SWITCH', 'ROUTER', 'FIREWALL', 'TRANSPORT', 'SERVER', 'OTHER')),
    CONSTRAINT uq_active_devices_code UNIQUE (code),
    CONSTRAINT uq_active_devices_rack_name UNIQUE (rack_id, name),
    CONSTRAINT fk_active_devices_rack FOREIGN KEY (rack_id) REFERENCES racks(id),
    INDEX idx_active_devices_rack_type (rack_id, device_type),
    INDEX idx_active_devices_vendor (vendor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE active_device_interfaces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    active_device_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    interface_type VARCHAR(24) NOT NULL,
    speed_label VARCHAR(40) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_active_device_interfaces_type CHECK (interface_type IN ('SFP', 'SFP_PLUS', 'SFP28', 'QSFP_PLUS', 'QSFP28', 'RJ45', 'OTHER')),
    CONSTRAINT uq_active_device_interfaces_name UNIQUE (active_device_id, name),
    CONSTRAINT fk_active_device_interfaces_device FOREIGN KEY (active_device_id) REFERENCES active_devices(id),
    INDEX idx_active_device_interfaces_device (active_device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE patch_panel_front_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patch_panel_port_id BIGINT UNSIGNED NOT NULL,
    active_device_interface_id BIGINT UNSIGNED NOT NULL,
    patch_cord_label VARCHAR(120) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_patch_panel_front_connections_port UNIQUE (patch_panel_port_id),
    CONSTRAINT uq_patch_panel_front_connections_interface UNIQUE (active_device_interface_id),
    CONSTRAINT fk_patch_panel_front_connections_port FOREIGN KEY (patch_panel_port_id) REFERENCES patch_panel_ports(id),
    CONSTRAINT fk_patch_panel_front_connections_interface FOREIGN KEY (active_device_interface_id) REFERENCES active_device_interfaces(id),
    INDEX idx_patch_panel_front_connections_interface (active_device_interface_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE OR REPLACE VIEW patch_panel_utilization AS
SELECT
    pp.id AS patch_panel_id,
    pp.port_count,
    COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL OR pfc.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) AS occupied_ports,
    pp.port_count - COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL OR pfc.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) AS available_ports,
    ROUND(COUNT(DISTINCT CASE
        WHEN fcp.patch_panel_port_id IS NOT NULL OR pca.id IS NOT NULL OR pcz.id IS NOT NULL OR pfc.id IS NOT NULL THEN ppp.id
        ELSE NULL
    END) * 100.0 / pp.port_count, 1) AS utilization_percent
FROM patch_panels pp
LEFT JOIN patch_panel_ports ppp ON ppp.patch_panel_id = pp.id
LEFT JOIN fiber_connection_ports fcp ON fcp.patch_panel_port_id = ppp.id
LEFT JOIN patch_cord_connections pca
    ON pca.a_port_id = ppp.id AND pca.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
LEFT JOIN patch_cord_connections pcz
    ON pcz.z_port_id = ppp.id AND pcz.operational_status IN ('PLANNED', 'ACTIVE', 'DAMAGED')
LEFT JOIN patch_panel_front_connections pfc ON pfc.patch_panel_port_id = ppp.id
GROUP BY pp.id, pp.port_count;
