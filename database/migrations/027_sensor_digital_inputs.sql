CREATE TABLE environmental_sensor_inputs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sensor_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    group_name VARCHAR(40) NULL,
    alarm_state_oid VARCHAR(255) NOT NULL,
    last_alarm_state TINYINT UNSIGNED NULL,
    last_read_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_environmental_sensor_inputs_sensor FOREIGN KEY (sensor_id)
        REFERENCES environmental_sensors (id) ON DELETE CASCADE,
    INDEX idx_environmental_sensor_inputs_sensor (sensor_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
