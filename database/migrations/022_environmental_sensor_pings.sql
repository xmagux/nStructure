CREATE TABLE environmental_sensor_pings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sensor_id BIGINT UNSIGNED NOT NULL,
    ok BOOLEAN NOT NULL,
    latency_ms DECIMAL(10,3) NULL,
    checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_environmental_sensor_pings_sensor FOREIGN KEY (sensor_id) REFERENCES environmental_sensors (id),
    INDEX idx_environmental_sensor_pings_sensor_time (sensor_id, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
