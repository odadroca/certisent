-- v0.3 -> v0.3.1 migration
-- Updates the schema version marker.
INSERT INTO system_state (`key`,`value`,`updated_at`) VALUES ('schema_version','0.3.1', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=VALUES(`updated_at`);
