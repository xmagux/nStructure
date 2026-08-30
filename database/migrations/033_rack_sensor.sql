ALTER TABLE racks
    ADD COLUMN sensor_id BIGINT UNSIGNED NULL AFTER row_label,
    ADD CONSTRAINT fk_racks_sensor FOREIGN KEY (sensor_id)
        REFERENCES environmental_sensors (id) ON DELETE SET NULL,
    ADD INDEX idx_racks_sensor (sensor_id);
