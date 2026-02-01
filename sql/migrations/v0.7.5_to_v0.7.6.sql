-- Certinel migration: v0.7.5 -> v0.7.6
-- Adds per-monitor certificate/public-key pinning settings.
-- Pinning is Certinel-defined (not HPKP preload).
--
-- New fields:
--  - monitor_settings.pin_mode: off|observe|enforce (default off)
--  - monitor_settings.pin_spki_sha256: base64 SHA-256 of SPKI (SubjectPublicKeyInfo)

ALTER TABLE monitor_settings
  ADD COLUMN pin_mode ENUM('off','observe','enforce') NOT NULL DEFAULT 'off' AFTER tls_validation_mode,
  ADD COLUMN pin_spki_sha256 VARCHAR(64) NULL AFTER pin_mode;
