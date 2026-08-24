ALTER TABLE ups_devices
    ADD COLUMN battery_count SMALLINT UNSIGNED NULL AFTER battery_replacement_interval_months,
    ADD COLUMN battery_type VARCHAR(160) NULL AFTER battery_count,
    ADD CONSTRAINT chk_ups_devices_battery_count CHECK (battery_count IS NULL OR battery_count BETWEEN 1 AND 200);
