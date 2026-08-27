CREATE TABLE environmental_sensor_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sensor_id BIGINT UNSIGNED NOT NULL,
    temperature DECIMAL(10,2) NULL,
    temperature_raw VARCHAR(64) NULL,
    temperature_ok BOOLEAN NOT NULL DEFAULT FALSE,
    humidity DECIMAL(10,2) NULL,
    humidity_raw VARCHAR(64) NULL,
    humidity_ok BOOLEAN NOT NULL DEFAULT FALSE,
    ping_ok BOOLEAN NULL,
    ping_latency_ms DECIMAL(10,3) NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_environmental_sensor_readings_sensor FOREIGN KEY (sensor_id) REFERENCES environmental_sensors (id),
    INDEX idx_environmental_sensor_readings_sensor_time (sensor_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
