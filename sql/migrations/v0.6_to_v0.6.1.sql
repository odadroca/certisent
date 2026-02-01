-- Certisent migration: v0.6 -> v0.6.1
-- Adds per-user UI locale preference.

ALTER TABLE users
  ADD COLUMN locale VARCHAR(16) NOT NULL DEFAULT 'en' AFTER role;
