ALTER TABLE environmental_sensors
    ADD COLUMN monitoring_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER ping_enabled,
    ADD COLUMN last_temperature DECIMAL(10,2) NULL AFTER monitoring_enabled,
    ADD COLUMN last_humidity DECIMAL(10,2) NULL AFTER last_temperature,
    ADD COLUMN last_ping_ok BOOLEAN NULL AFTER last_humidity,
    ADD COLUMN last_ping_latency_ms DECIMAL(10,2) NULL AFTER last_ping_ok,
    ADD COLUMN last_read_at TIMESTAMP NULL AFTER last_ping_latency_ms;
