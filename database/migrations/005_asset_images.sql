CREATE TABLE asset_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(24) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(96) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    width_px INT UNSIGNED NOT NULL,
    height_px INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_asset_images_entity_type CHECK (entity_type IN ('RACK', 'PATCH_PANEL')),
    CONSTRAINT chk_asset_images_mime_type CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp')),
    CONSTRAINT uq_asset_images_storage_path UNIQUE (storage_path),
    INDEX idx_asset_images_entity (entity_type, entity_id),
    INDEX idx_asset_images_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
