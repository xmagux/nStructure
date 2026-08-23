ALTER TABLE fiber_nodes
    ADD COLUMN rack_id BIGINT UNSIGNED NULL AFTER server_room_id,
    ADD CONSTRAINT fk_fiber_nodes_rack FOREIGN KEY (rack_id) REFERENCES racks (id),
    ADD INDEX idx_fiber_nodes_rack (rack_id);

UPDATE fiber_nodes fn
JOIN patch_panels pp ON pp.fiber_node_id = fn.id
SET fn.rack_id = pp.rack_id
WHERE fn.node_type = 'PATCH_PANEL';
