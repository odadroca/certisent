# Release notes — v0.6.x

This document tracks patch releases in the v0.6 series.

## v0.6.1 (2026-01-31)

### Added
- Localization foundation (default-preserving):
  - Added `users.locale` (default: `en`) for per-user UI language preference.
  - Added minimal i18n module: `app/i18n.php` with `current_locale()` and `t()` (fallback to English).
  - Added locale catalogs: `app/locales/en.php`, `app/locales/pt.php` (minimal seed keys).
  - Added Settings → Language selector (persists to `users.locale` when the column exists).
  - Set `<html lang="...">` dynamically from the current locale.

### Upgrade notes
- Existing deployments upgrading from v0.6 must apply:
  - `sql/migrations/v0.6_to_v0.6.1.sql` (adds `users.locale`).
- Defaults remain English (`en`) if the column is missing or unset.

## v0.6 (2026-01-31)

### Fixed
- Admin actions returning HTTP 500 after completing the intended action:
  - Test email send (`/public/admin/email.php`) could send successfully but then fail during audit logging.
  - Run outbox now (`/public/admin/outbox_run.php`) could fail due to missing UI helpers.
- Outbox and worker batching compatibility:
  - Avoided PDO driver incompatibilities around parameterized `LIMIT`, restoring reliable outbox processing in some environments.
