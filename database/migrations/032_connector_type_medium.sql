ALTER TABLE connector_types
    ADD COLUMN medium ENUM('FIBER', 'COPPER') NOT NULL DEFAULT 'FIBER' AFTER code;

INSERT INTO connector_types (code, translation_key, active, medium)
VALUES ('RJ45', 'connector.rj45', TRUE, 'COPPER')
ON DUPLICATE KEY UPDATE active = TRUE, medium = 'COPPER';
