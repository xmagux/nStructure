CREATE TABLE environmental_sensor_channels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sensor_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    channel_type ENUM('temperature', 'humidity') NOT NULL,
    value_oid VARCHAR(255) NOT NULL,
    value_divisor DECIMAL(10,4) NOT NULL DEFAULT 1,
    last_reading DECIMAL(10,2) NULL,
    last_read_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_environmental_sensor_channels_sensor FOREIGN KEY (sensor_id)
        REFERENCES environmental_sensors (id) ON DELETE CASCADE,
    INDEX idx_environmental_sensor_channels_sensor (sensor_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
