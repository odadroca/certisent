# Certisent v0.6 UI map

All routes are relative to the `/public/` folder.

## Public (not signed in)

- `index.php`
  - Landing page + quick (stateless) TLS check form.
- `login.php`
  - Sign in.
- `register.php`
  - Register new account (subject to `REGISTRATION_MODE` and optional `SETUP_ADMIN_TOKEN`).

## Signed in (viewer/admin/auditor)

- `dashboard.php`
  - Monitors list/cards.
  - Status badges + days remaining.
  - Actions (role-dependent): View/Edit/Delete.
  - “Check now (all)” (creates a `worker_jobs` record and processes a short slice immediately).
- `history.php`
  - Event history (role/ownership dependent).
- `events.php`
  - Filterable events list; can be restricted by `monitor_id`.
- `settings.php`
  - Notification channel settings (email/webhooks).
  - RSS feed token rotate + RSS URL display.
  - API quick reference (Bearer token usage).
- `logout.php`
  - GET shows confirmation page.
  - POST performs logout (POST+CSRF).

## Monitor management

- `monitor_add.php` (viewer/admin)
- `monitor_edit.php` (viewer/admin; owner/admin)
- `monitor_delete.php` (viewer/admin; owner/admin)
- `monitor_view.php?id=<monitor_id>` (viewer/admin/auditor; owner/admin/auditor)
  - Latest snapshot details
  - Raw PEM (latest snapshot)
  - Snapshots history
  - Recent events
  - “Check now” for a single monitor (viewer/admin)

- `monitor_check.php` (POST) (viewer/admin; owner/admin)
  - Executes a single stored check and redirects back to `monitor_view.php`.

## “Check now (all)” job

- `check_now_all.php` (POST) (viewer/admin)
  - Creates a `worker_jobs` record (`type=run_all`) and processes a time-boxed slice immediately.
  - Completion continues across subsequent worker runs.

## RSS

- `rss.php?token=<rss_token>`
  - RSS feed of events for a user.
  - v0.5.2+ can restrict non-admin tokens to only owned monitors (see `RSS_INCLUDE_SYSTEM_EVENTS`).

## Admin (admin only)

- `admin/users.php`
  - Manage users.
- `admin/user_edit.php?id=<user_id>`
  - Edit user role/status.
- `admin/monitors.php`
  - Monitor inventory + status filtering.
- `admin/audit.php`
  - Audit log.
- `admin/system.php`
  - Operator diagnostics: worker heartbeat, recent event counts, rate-limit summary, job/outbox summaries.
- `admin/email.php`
  - Outbound email configuration summary (non-secret) + test email.
- `admin/api_keys.php`
  - Create/revoke scoped Bearer tokens for API/worker calls.
- `admin/outbox.php`
  - Notification delivery queue: pending/sent/failed, attempts, next retry, last error.

### Admin POST endpoints

- `admin/outbox_run.php` (POST)
  - Processes the outbox immediately.
- `admin/job_cancel.php` (POST)
  - Cancels a pending/running `worker_jobs` record.
