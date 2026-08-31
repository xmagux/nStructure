ALTER TABLE environmental_sensors
    ADD COLUMN temperature_label VARCHAR(80) NULL AFTER temperature_max,
    ADD COLUMN humidity_label VARCHAR(80) NULL AFTER humidity_max;
