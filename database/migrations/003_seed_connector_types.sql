INSERT INTO connector_types (code, translation_key, active) VALUES
    ('E2000', 'connector.e2000', TRUE),
    ('SC-PC', 'connector.sc_pc', TRUE),
    ('SC-APC', 'connector.sc_apc', TRUE),
    ('LC', 'connector.lc', TRUE)
ON DUPLICATE KEY UPDATE
    translation_key = VALUES(translation_key),
    active = VALUES(active);
