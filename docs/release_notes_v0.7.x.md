# Release notes (v0.7.x)

## v0.7.6

### Added (default-preserving)
- Certisent-defined certificate/public-key pinning (not HPKP preload): per-monitor `monitor_settings.pin_mode` (`off|observe|enforce`, default `off`) plus `monitor_settings.pin_spki_sha256` (base64 sha256 of SPKI).
- Quick Check displays `SPKI sha256 (pin)` (copy/paste value).
- Worker: when pinning is enabled and a pin is set, emits event `tls_pin_mismatch` on mismatch (severity: `warn` in `observe`, `critical` in `enforce`).

### DB migration
- New columns require running: `sql/migrations/v0.7.5_to_v0.7.6.sql`.

## v0.7.5

### Added (default-preserving)
- UI: per-monitor selector for `monitor_settings.tls_validation_mode` (off/observe/enforce) on Monitor → Edit.
- UI: Dashboard and Monitor view show a last-known TLS validation summary (mode + hostname/trust result), separate from expiry/change status.

### DB migration
- None.

## v0.7.4

### Added (default-preserving)
- When `monitor_settings.tls_validation_mode` is `observe|enforce`, the worker now emits new event types (notification categories) for invalid TLS states:
  - `tls_wrong_host`
  - `tls_self_signed`
  - `tls_untrusted_root`
- Event dedupe: these TLS invalid-state events are created only when the classification changes or the certificate fingerprint changes (avoids repeated event spam).

### DB migration
- None.

## v0.7.3

### Added (default-preserving)
- Opt-in TLS trust validation probe (chain trust using the system CA bundle), separate from CertFetcher.
- Trust validation results persisted to `monitors.trust_ok` / `monitors.trust_category` / `monitors.trust_error` when `monitor_settings.tls_validation_mode` is not `off`.
- Quick Check now shows an immediate prominent warning when trust validation fails, mapped into:
  - `tls_self_signed`
  - `tls_untrusted_root`
  - `tls_untrusted_unknown`

### DB migration
- New columns require running: `sql/migrations/v0.7.2_to_v0.7.3.sql`.

## v0.7.2

### Added (default-preserving)
- Opt-in per-monitor TLS hostname validation mode: `monitor_settings.tls_validation_mode` (`off|observe|enforce`, default `off`).
- Hostname validation results persisted to `monitors.hostname_ok` / `monitors.hostname_error` when mode is not `off`.

### DB migration
- New columns require running: `sql/migrations/v0.7.1_to_v0.7.2.sql`.

### UI
- Quick Check now shows an immediate prominent warning when a hostname mismatch is detected ("wrong.host" style), while still displaying parsed certificate details.

## v0.7.1

### Fixed
- Locale preference now persists across logout/login cycles: the login flow resets the cached session locale to the user's saved `users.locale` value (fallback: `en`).

## v0.7

### Added
- i18n audit CLI tool: `tools/i18n_audit.php` scans for `t('...')` calls and reports missing keys per locale.
- Opt-in locale-aware formatting for UI dates/numbers via `I18N_FORMAT_DATES` (default off).
- Documentation: `docs/localization.md` describing locale selection, adding a locale, audit tool, and formatting.

### Changed (default-preserving)
- Added UI helpers `ui_dt()` / `ui_num()` that apply locale formatting only when `I18N_FORMAT_DATES` is enabled and PHP `intl` is available.
- Updated key UI pages to use `ui_dt()` and `ui_num()` for display fields; when formatting is disabled, output is unchanged.

### Notes
- Formatting is UTC-based and remains labeled as UTC where previously indicated.
- If `intl` is missing, enabling `I18N_FORMAT_DATES` has no effect (safe fallback).
