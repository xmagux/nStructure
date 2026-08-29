CREATE TABLE alert_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    name VARCHAR(160) NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_alert_recipients_email UNIQUE (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE alert_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_alert_groups_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE alert_group_members (
    group_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, recipient_id),
    CONSTRAINT fk_alert_group_members_group FOREIGN KEY (group_id)
        REFERENCES alert_groups (id) ON DELETE CASCADE,
    CONSTRAINT fk_alert_group_members_recipient FOREIGN KEY (recipient_id)
        REFERENCES alert_recipients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- target_id is polymorphic (recipient or group id, per target_type) — same
-- untyped-reference pattern already used by audit_events.entity_type/id,
-- so no single FK is possible here.
CREATE TABLE sensor_alert_targets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sensor_id BIGINT UNSIGNED NOT NULL,
    target_type ENUM('recipient', 'group') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sensor_alert_targets_sensor FOREIGN KEY (sensor_id)
        REFERENCES environmental_sensors (id) ON DELETE CASCADE,
    CONSTRAINT uq_sensor_alert_targets UNIQUE (sensor_id, target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE alert_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    repeat_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO alert_settings (id, repeat_interval_minutes) VALUES (1, 60);

-- One row per sensor tracks whether it's currently considered "in alarm"
-- for email purposes, independent of the tile's own live-poll state, so
-- the background daemon (which has no browser session) knows when to send
-- the first alarm mail, when to repeat it, and when to send the
-- all-clear.
CREATE TABLE sensor_alert_state (
    sensor_id BIGINT UNSIGNED PRIMARY KEY,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    reasons VARCHAR(255) NULL,
    started_at TIMESTAMP NULL,
    last_notified_at TIMESTAMP NULL,
    CONSTRAINT fk_sensor_alert_state_sensor FOREIGN KEY (sensor_id)
        REFERENCES environmental_sensors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
