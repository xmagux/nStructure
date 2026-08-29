ALTER TABLE server_rooms
    ADD COLUMN sensor_id BIGINT UNSIGNED NULL AFTER floor,
    ADD CONSTRAINT fk_server_rooms_sensor FOREIGN KEY (sensor_id)
        REFERENCES environmental_sensors (id) ON DELETE SET NULL,
    ADD INDEX idx_server_rooms_sensor (sensor_id);
