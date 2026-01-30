-- v0.2.1 -> v0.3 migration
-- Run once. If you already dropped/recreated with schema.sql, do not run this.

-- 1) Add denormalized monitor fields used by v0.3 UI/worker.
ALTER TABLE monitors
  ADD COLUMN last_checked_at DATETIME NULL,
  ADD COLUMN last_status ENUM('ok','warn','critical','unknown') NULL,
  ADD COLUMN last_fingerprint_sha256 VARCHAR(128) NULL,
  ADD COLUMN last_issuer_cn VARCHAR(255) NULL,
  ADD COLUMN last_valid_from DATETIME NULL,
  ADD COLUMN last_valid_to DATETIME NULL,
  ADD COLUMN last_days_remaining INT NULL,
  ADD COLUMN last_error VARCHAR(255) NULL;

CREATE INDEX idx_monitors_last_checked ON monitors(last_checked_at);

-- 2) API keys for scoped worker access
CREATE TABLE api_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  token_hash_sha256 CHAR(64) NOT NULL,
  scopes_json JSON NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_api_token_hash (token_hash_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Reliable delivery outbox
CREATE TABLE notification_outbox (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_id INT NOT NULL,
  channel ENUM('email','slack','teams') NOT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  next_retry_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  payload_json JSON NOT NULL,
  dedupe_key CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_outbox_dedupe (dedupe_key),
  KEY idx_outbox_status_next (status, next_retry_at),
  CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_outbox_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Seed schema version marker (v0.3)
INSERT INTO system_state (`key`,`value`,`updated_at`) VALUES ('schema_version','0.3', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=VALUES(`updated_at`);
