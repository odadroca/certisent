# Release notes — v0.6.x

This file tracks changes introduced across the v0.6.x line.

## v0.6.0 — Delivery reliability & admin 500 fixes (base: v0.5.9)

### Fixed
- Admin **Send test email** (`/public/admin/email.php`) no longer returns HTTP 500 after a successful send.
  - Root cause: a post-send audit call passed a user array where an integer `user_id` was required (fatal under strict typing).
- Admin **Run outbox now** (`/public/admin/outbox_run.php`) no longer returns HTTP 500.
  - Root cause: missing UI helper include (`flash_set()`, `url_for()`), causing a fatal.
- Outbound notification delivery after:
  - a worker run; or
  - an immediate “check now”
  is restored when the warn threshold is met.
  - Root cause: reliance on bound parameters for `LIMIT` in some PDO/MySQL configurations prevented batching/outbox processing.

### Implementation notes (behavior preserved)
- Replaced `LIMIT :param` bindings with clamped integer interpolation for batch sizing in:
  - outbox processing; and
  - worker batching.
  This avoids driver incompatibilities while keeping the same batching semantics.

### Operational / upgrade notes
- No database migrations required for v0.6.0.
- If you have cron/worker automation, no schedule changes are required; this release restores delivery where runs previously completed without sending.

### Files changed in v0.6.0 (from v0.5.9)
- `app/config.php`
- `app/services/Notifier.php`
- `app/services/Worker.php`
- `public/admin/email.php`
- `public/admin/outbox_run.php`
- `README.md`
- `docs/api.md`
- `docs/architecture.md`
- `docs/deploy.md`
- `docs/ops_runbook.md`
- `docs/ui_map.md`
- `docs/release_notes_v0.5.x.md`
- `docs/file_registry.md`

### Related notes
- Security hardening notes that landed during the v0.5.x line (including the pre-1.0 v0.6 security polish) remain tracked in `docs/release_notes_v0.5.x.md`.
