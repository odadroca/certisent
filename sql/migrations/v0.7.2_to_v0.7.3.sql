-- Certisent migration: v0.7.2 -> v0.7.3
-- Adds opt-in TLS trust validation fields (chain trust using system CA bundle).
-- Persisted only when monitor_settings.tls_validation_mode != 'off'.
--
-- New fields:
--  - monitors.trust_ok (1/0)
--  - monitors.trust_category: tls_self_signed | tls_untrusted_root | tls_untrusted_unknown
--  - monitors.trust_error (short diagnostic)

ALTER TABLE monitors
  ADD COLUMN trust_ok TINYINT(1) NULL AFTER hostname_error,
  ADD COLUMN trust_category ENUM('tls_self_signed','tls_untrusted_root','tls_untrusted_unknown') NULL AFTER trust_ok,
  ADD COLUMN trust_error VARCHAR(255) NULL AFTER trust_category;
