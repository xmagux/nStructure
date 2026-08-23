ALTER TABLE locations
    ADD COLUMN icon_key VARCHAR(24) NOT NULL DEFAULT 'loc-office' AFTER name,
    ADD CONSTRAINT chk_locations_icon_key CHECK (icon_key IN (
        'loc-office', 'loc-datacenter', 'loc-server-room', 'loc-tower', 'loc-warehouse',
        'loc-campus', 'loc-cloud', 'loc-satellite', 'loc-factory', 'loc-globe'
    ));

ALTER TABLE asset_images
    DROP CHECK chk_asset_images_entity_type,
    ADD CONSTRAINT chk_asset_images_entity_type CHECK (entity_type IN ('RACK', 'PATCH_PANEL', 'LOCATION'));
