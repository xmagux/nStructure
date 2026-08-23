ALTER TABLE patch_panel_ports
    ADD COLUMN remote_endpoint_label VARCHAR(255) NULL AFTER label;
