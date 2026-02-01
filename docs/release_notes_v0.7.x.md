# Release notes (v0.7.x)

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
