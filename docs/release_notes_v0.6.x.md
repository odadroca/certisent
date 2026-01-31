# Release notes — v0.6.x

This document tracks patch releases in the v0.6 series.

## v0.6.4 (2026-01-31)

### Added / Changed
- Localized notification content (default-preserving):
  - Email subject/body generated via `t()` using recipient `users.locale` (fallback `en`).
  - Slack/Teams webhook text generated via `t()` using recipient `users.locale` (fallback `en`).
  - Added notification template keys in `app/locales/en.php` and `app/locales/pt.php`.

### Upgrade notes
- No schema changes in v0.6.4.
- English remains the default.

## v0.6.3 (2026-01-31)

### Added / Changed
- Localization expansion (default-preserving):
  - Localized core monitoring UI pages using `t()` with English fallback:
    - `public/dashboard.php`
    - `public/monitor_add.php`
    - `public/monitor_edit.php`
    - `public/monitor_view.php`
    - `public/monitor_delete.php`
    - `public/events.php`
    - `public/history.php`
  - Expanded locale catalogs: `app/locales/en.php`, `app/locales/pt.php`.

### Upgrade notes
- No schema changes in v0.6.3.
- English remains the default (`en`) when unset or unsupported.

## v0.6.2 (2026-01-31)

### Added / Changed
- Localization expansion (default-preserving):
  - Wrapped shared layout/navigation strings in `app/ui.php` using `t()` with English fallback.
  - Added additive helper `flash_set_key()` (preserves `flash_set()`).
  - Localized guest/auth pages: `public/index.php`, `public/login.php`, `public/register.php`.
  - Expanded locale catalogs: `app/locales/en.php`, `app/locales/pt.php`.

### Upgrade notes
- No schema changes beyond v0.6.1; `users.locale` remains the only required column for per-user language preference.
- English remains the default (`en`) when unset or unsupported.

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
