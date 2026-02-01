-- Certinel migration: v0.7.1 -> v0.7.2
-- Adds opt-in TLS identity validation fields:
--  - per-monitor mode: monitor_settings.tls_validation_mode (default: off)
--  - last-known hostname validation result: monitors.hostname_ok / monitors.hostname_error

ALTER TABLE monitor_settings
  ADD COLUMN tls_validation_mode ENUM('off','observe','enforce') NOT NULL DEFAULT 'off' AFTER notify_on_renewal;

ALTER TABLE monitors
  ADD COLUMN hostname_ok TINYINT(1) NULL AFTER last_error,
  ADD COLUMN hostname_error VARCHAR(255) NULL AFTER hostname_ok;
