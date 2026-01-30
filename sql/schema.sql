-- Certinel schema (MySQL 8+ recommended)
-- All timestamps stored as UTC strings (DATETIME).

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','viewer','auditor') NOT NULL DEFAULT 'viewer',
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  notify_channels_json JSON NULL,
  rss_token VARCHAR(64) NOT NULL,
  failed_login_count INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS monitors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  url VARCHAR(512) NOT NULL,
  host VARCHAR(255) NOT NULL,
  port INT NOT NULL DEFAULT 443,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_monitors_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_monitors_user ON monitors(user_id);
CREATE INDEX idx_monitors_host ON monitors(host);

CREATE TABLE IF NOT EXISTS monitor_settings (
  monitor_id INT PRIMARY KEY,
  notify_days_before_expiry INT NOT NULL DEFAULT 30,
  check_frequency_minutes INT NOT NULL DEFAULT 60,
  notify_on_change TINYINT(1) NOT NULL DEFAULT 1,
  notify_on_renewal TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_settings_monitor FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cert_snapshots (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  monitor_id INT NOT NULL,
  fetched_at DATETIME NOT NULL,
  serial VARCHAR(128) NULL,
  fingerprint_sha256 VARCHAR(128) NULL,
  issuer_cn VARCHAR(255) NULL,
  subject_cn VARCHAR(255) NULL,
  valid_from DATETIME NULL,
  valid_to DATETIME NULL,
  raw_pem MEDIUMTEXT NULL,
  status ENUM('ok','warn','critical','unknown') NOT NULL,
  error VARCHAR(255) NULL,
  days_remaining INT NULL,
  CONSTRAINT fk_snapshots_monitor FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_snapshots_monitor_time ON cert_snapshots(monitor_id, fetched_at);
CREATE INDEX idx_snapshots_fingerprint ON cert_snapshots(fingerprint_sha256);

CREATE TABLE IF NOT EXISTS events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  monitor_id INT NULL,
  type VARCHAR(64) NOT NULL,
  severity ENUM('info','warn','critical') NOT NULL,
  message VARCHAR(1024) NOT NULL,
  created_at DATETIME NOT NULL,
  meta_json JSON NULL,
  CONSTRAINT fk_events_monitor FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_events_monitor_time ON events(monitor_id, created_at);
CREATE INDEX idx_events_type_time ON events(type, created_at);

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id INT NULL,
  action VARCHAR(128) NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(512) NULL,
  created_at DATETIME NOT NULL,
  meta_json JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_audit_time ON audit_log(created_at);
CREATE INDEX idx_audit_actor_time ON audit_log(actor_user_id, created_at);

CREATE TABLE IF NOT EXISTS system_state (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` VARCHAR(1024) NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: initialize worker heartbeat keys (optional)
INSERT INTO system_state (`key`,`value`,updated_at)
  VALUES ('last_cron_run_at','',UTC_TIMESTAMP())
  ON DUPLICATE KEY UPDATE updated_at=VALUES(updated_at);
