INSERT INTO connector_types (code, translation_key, active) VALUES
    ('E2000-APC', 'connector.e2000_apc', TRUE),
    ('E2000-UPC', 'connector.e2000_upc', TRUE),
    ('LC-APC', 'connector.lc_apc', TRUE),
    ('LC-UPC', 'connector.lc_upc', TRUE),
    ('SC', 'connector.sc', TRUE),
    ('SC-UPC', 'connector.sc_upc', TRUE),
    ('OTHER', 'connector.other', TRUE)
ON DUPLICATE KEY UPDATE
    translation_key = VALUES(translation_key),
    active = VALUES(active);
