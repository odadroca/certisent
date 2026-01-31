-- Certinel migration: v0.6.6 -> v0.6.7
-- Adds per-user notification repeat count (default 1).

ALTER TABLE users
  ADD COLUMN notify_repeat_count INT NOT NULL DEFAULT 1 AFTER notify_channels_json;
