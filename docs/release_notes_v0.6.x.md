# Release notes — v0.6.x

This document tracks patch releases in the v0.6 series.

## v0.6.7 (2026-01-31)

### Added / Changed
- Settings: added per-user **Notification repeats** control (default 1) to send each notification N times (1–5) for redundancy.
  - DB: new `users.notify_repeat_count` column (default: 1).
  - Notifier: enqueues up to N outbox entries per event/channel using dedupe keys that preserve previous behavior when N=1.

### Fixed
- Admin → Monitors: fixed empty-looking **Status filter** dropdown (option labels were not rendered due to malformed `<option>` markup).
- Admin → System: fixed overlapping card layout by keeping diagnostic cards within the same grid container.

### Upgrade notes
- Schema change in v0.6.7: apply `sql/migrations/v0.6.6_to_v0.6.7.sql`.


## v0.6.6 (2026-01-31)

### Fixed
- Admin: fixed HTTP 500 on `public/admin/api_keys.php` (syntax error in owner-required flash message).
- Admin: restored “Run outbox now” action in the Outbox UI (`public/admin/outbox.php`) and kept `public/admin/outbox_run.php` as the handler.
- Notifications: fixed email/webhook sending failure caused by `Call to undefined function format_event_meta()` by internalizing meta formatting in `app/services/Notifier.php` (workers don’t load UI helpers).

### Upgrade notes
- No schema changes in v0.6.6.
- Default remains English; localization remains opt-in per user (`users.locale`).

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


## v0.6.5
- Localization: admin UI pages (`public/admin/*.php`) now use translation keys via `t()`, with EN/PT catalogs expanded.
- No behavioral changes; default remains English.
