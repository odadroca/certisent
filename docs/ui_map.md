# Certinel v0.3 UI map

All routes are relative to the `/public/` folder.

## Public (not signed in)

- `index.php`
  - Landing page + quick (stateless) TLS check form.
- `login.php`
  - Sign in.
- `register.php`
  - Register new account.

## Signed in (viewer/admin/auditor)

- `dashboard.php`
  - Monitors list/cards.
  - Status badges + days remaining.
  - Links to View/Edit/Delete (role-dependent).
- `history.php`
  - Event history.
  - Viewer: only own monitors.
  - Admin/Auditor: global.
- `settings.php`
  - Notification channel settings (email/webhooks).
  - RSS feed token rotate + RSS URL display.
  - Worker API quick reference.
- `logout.php`
  - Sign out.

## Monitor management

- `monitor_add.php` (viewer/admin; owner context)
  - Add a monitor URL and settings.
- `monitor_edit.php` (viewer/admin; owner/admin)
  - Edit monitor settings, including:
    - enabled
    - check frequency
    - notify days before expiry
    - notify on change
    - notify on renewal
- `monitor_delete.php` (viewer/admin; owner/admin)
  - Stop monitoring (delete).

## Monitor / certificate administration

- `monitor_view.php?id=<monitor_id>` (viewer/admin/auditor; owner/admin/auditor)
  - Per-monitor settings summary.
  - Latest snapshot details.
  - Raw PEM (latest snapshot).
  - Recent snapshots table.
  - Recent events table.
  - Actions:
    - Edit (viewer)
    - Check now (store) (viewer/admin; not auditor)

- `monitor_check.php` (POST) (viewer/admin; owner/admin)
  - Executes a single stored check via `Worker::checkOne()` and redirects back to `monitor_view.php`.

## Other views

- `events.php` (viewer/admin/auditor)
  - Filterable events list; can be restricted by `monitor_id`.
- `rss.php?token=<rss_token>`
  - RSS feed of events for a user.
- `check_now.php` (POST)
  - Stateless quick check handler used by `index.php`.

## Admin

- `/admin/users.php` (admin)
  - Manage users.
- `/admin/user_edit.php?id=<user_id>` (admin)
  - Edit user role + status.
- `/admin/monitors.php` (admin)
  - Monitor inventory (latest snapshot per monitor) + status filtering.
- `/admin/audit.php` (admin)
  - Audit log.

- `/admin/system.php` (admin)
  - Operator diagnostics: worker heartbeat, event counts (last 24h), and recent system events.

- `/admin/api_keys.php` (admin)
  - Create/revoke scoped Bearer tokens for API/worker calls.

- `/admin/outbox.php` (admin)
  - Notification delivery queue: pending/sent/failed, attempts, next retry, last error.
