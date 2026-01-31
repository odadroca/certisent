-- v0.5.5 -> v0.5.6 migration
-- Adds optional API key ownership + key type for least privilege.
-- Safe to run on systems where api_keys already contains existing keys (defaults to system scope).

ALTER TABLE api_keys
  ADD COLUMN key_type VARCHAR(16) NOT NULL DEFAULT 'system',
  ADD COLUMN owner_user_id INT NULL;

CREATE INDEX idx_api_keys_owner ON api_keys(owner_user_id);
