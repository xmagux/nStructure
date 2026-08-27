ALTER TABLE environmental_sensors
    ADD COLUMN temperature_min DECIMAL(10,2) NULL AFTER temperature_divisor,
    ADD COLUMN temperature_max DECIMAL(10,2) NULL AFTER temperature_min,
    ADD COLUMN humidity_min DECIMAL(10,2) NULL AFTER humidity_divisor,
    ADD COLUMN humidity_max DECIMAL(10,2) NULL AFTER humidity_min;
