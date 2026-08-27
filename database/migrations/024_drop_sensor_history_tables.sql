-- Sensor time-series history moved to VictoriaMetrics; MySQL keeps only
-- sensor configuration (environmental_sensors).
DROP TABLE IF EXISTS environmental_sensor_pings;
DROP TABLE IF EXISTS environmental_sensor_readings;
