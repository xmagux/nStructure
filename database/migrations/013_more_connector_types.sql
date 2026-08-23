INSERT INTO connector_types (code, translation_key, active) VALUES
    ('FC', 'connector.fc', TRUE),
    ('ST', 'connector.st', TRUE),
    ('MPO', 'connector.mpo', TRUE)
ON DUPLICATE KEY UPDATE
    translation_key = VALUES(translation_key),
    active = VALUES(active);
