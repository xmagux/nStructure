ALTER TABLE audit_events DROP FOREIGN KEY fk_audit_events_user;
DROP TABLE app_users;
ALTER TABLE audit_events ADD CONSTRAINT fk_audit_events_user FOREIGN KEY (user_id) REFERENCES users (id);
