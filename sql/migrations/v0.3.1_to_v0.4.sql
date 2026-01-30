-- Certinel migration: v0.3.1 -> v0.4
-- Adds async worker_jobs table and bumps schema_version.

CREATE TABLE IF NOT EXISTS worker_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('run_all') NOT NULL,
  requested_by_user_id INT NULL,
  status ENUM('pending','running','completed','cancelled','failed') NOT NULL DEFAULT 'pending',
  total_processed INT NOT NULL DEFAULT 0,
  last_monitor_id INT NULL,
  error VARCHAR(512) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  CONSTRAINT fk_jobs_user FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_jobs_status_updated ON worker_jobs(status, updated_at);

INSERT INTO system_state (`key`,`value`,`updated_at`) VALUES ('schema_version','0.4', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=VALUES(`updated_at`);
