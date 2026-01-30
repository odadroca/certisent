<p align="center"><img src="https://i.postimg.cc/qMpPkTVz/certinel-neg.png" alt="Certinel" width="200" height="200"></p>

# Certinel (certificate sentinel) — v0.5.3
Certinel is a lightweight TLS/SSL certificate monitoring service that **live-fetches** the certificate presented by an endpoint (SNI-capable), stores immutable snapshots, detects changes (renewals/rotations), and notifies interested parties before outages happen.

It is designed to be simple to host (shared hosting or VPS), easy to operate (cron-driven worker), and explicit about what it is observing: **the certificate the endpoint actually serves**.

## Highlights (v0.5.3)
- Added an SSRF policy framework for outbound TLS checks (opt-in):
  - `SSRF_MODE=legacy` (default) preserves v0.4.x behavior (no SSRF blocking).
  - `SSRF_MODE=public_only` blocks private/reserved IP ranges.
  - `SSRF_MODE=allowlist_private` blocks private/reserved ranges unless allowlisted.
- Added allowlist primitives: `SSRF_ALLOW_CIDRS`, `SSRF_ALLOW_HOSTS`, `SSRF_ALLOW_PORTS`.
- Policy enforced for: monitor checks, quick checks, and `/api/v1/check` URL-based checks.

- Webhook egress hardening (opt-in):
  - `WEBHOOK_MODE=legacy` (default) preserves v0.4.x behavior.
  - `WEBHOOK_MODE=public_only` requires `https://` and blocks private/reserved IP ranges.
  - `WEBHOOK_MODE=allowlist` requires `https://` and allows private/reserved targets only if allowlisted via `SSRF_ALLOW_*`.
  - Redirect following is disabled to prevent redirect-based bypass.

- RSS tenancy hardening (safe default):
  - Non-admin RSS tokens no longer receive system/global events (`monitor_id IS NULL`).
  - `RSS_INCLUDE_SYSTEM_EVENTS=false` by default; only `admin`/`auditor` RSS tokens may include system events when enabled.

- Admin bootstrap hardening (v0.5.3, safe defaults):
  - `REGISTRATION_MODE=open|invite|closed` (default `open`).
  - Optional `SETUP_ADMIN_TOKEN`: required to claim the first admin (and required for all registrations in `invite` mode).
  - Optional `ADMIN_EMAIL`: on a fresh install (no users yet), only this email can claim the first admin.
  - Admin → System: button to disable/enable registrations post-setup (DB flag).

## Notes / limitations
- API auth:
  - Preferred: API keys created in the admin UI (stored hashed in `api_keys`).
  - Legacy: `.env` `API_WORKER_KEY` is still accepted and is treated as full-scope (`*`) for upgrade safety.
- No automated test suite is shipped yet (manual verification required for changes).
- App version vs DB schema version:
  - App version may advance in patch releases.
  - DB schema version remains `0.4` unless you apply a future migration.
- Notifications: email (PHP `mail()`) + optional Slack/Teams webhooks (basic POST). RSS is read-only.
- Registration (v0.5.3):
  - `REGISTRATION_MODE=closed` disables new registrations.
  - Admin can also disable registrations via Admin → System (DB flag).
  - `SETUP_ADMIN_TOKEN` and `ADMIN_EMAIL` only affect fresh installs (first admin claim).

- RSS (v0.5.2):
  - Non-admin RSS tokens only return events for monitors owned by that user.
  - System/global events are excluded unless `RSS_INCLUDE_SYSTEM_EVENTS=true` and the token belongs to an `admin`/`auditor`.
- “False positives” are mitigated with a confirm re-check on certificate change (configurable sample count).
- No background job queue; worker sends notifications inline.
- SSRF policy (v0.5):
  - Only applies in non-`legacy` modes.
  - `public_only` and `allowlist_private` may block targets that resolve to private/reserved IPs.
  - When blocked, checks return errors like `ssrf_blocked: <reason>`.
- Webhook policy (v0.5.1):
  - Only applies in non-`legacy` `WEBHOOK_MODE`.
  - When blocked, webhook delivery may record errors like `webhook_ssrf_blocked: <reason>`.

## Quick start (shared hosting)
1. Upload the contents of this zip to your hosting under `public_html/certinel/` (or similar).
2. Copy `.env.example` to `.env` and set values.
3. Create the MySQL DB + user, then import `sql/schema.sql`.
4. Visit `/public/` and create the first admin user (first registered user becomes admin).
5. Add monitors from the dashboard.
6. Create a cron job running `php /path/to/certinel/scripts/worker.php --due` every 5–15 minutes.

Detailed steps: see `docs/deploy.md` and `docs/ops_runbook.md`.

## Security baseline
- Password hashing (`password_hash` / `password_verify`)
- CSRF tokens on mutating forms
- Prepared statements (PDO)
- Role checks on every action
- Session hardening (`SameSite`, `HttpOnly`, `Secure` when HTTPS)

## Features
- **Live certificate fetching (SNI-capable)** for host:port endpoints
- **Change detection**
  - fingerprint (SHA-256) change detection (renewal/rotation)
  - optional confirm/re-sample strategy to reduce false positives
- **Expiry warnings** based on days remaining thresholds
- **Failure detection** (connect/fetch/parse failures tracked as events)
- **History / auditability**
  - immutable certificate snapshots
  - events timeline
  - audit log for user actions
- **Web UI**
  - roles: `admin`, `viewer`, `auditor`
  - manage monitors, thresholds, notification settings
- **CLI worker (cron)** to run checks on schedule
- **Notifications**
  - email via `mail()` or SMTP
  - optional generic HTTP hook transport
- **Minimal HTTP API** for triggering checks remotely (optional)
- **RSS feed endpoint** for consuming events in external tooling

## Installation

### Prerequisites
- PHP **8.1+** (tested with PHP 8.3)
- PHP extensions:
  - `openssl`
  - `pdo` + `pdo_mysql`
- MySQL / MariaDB
- A web server (Apache or Nginx)
- Cron (recommended) to run the worker regularly

### Steps
1. Clone the repository:
   ```bash
   git clone https://github.com/odadroca/certinel/
   cd certinel
   ```

2. Create a database and user in MySQL/MariaDB.

3. Import the schema:
   ```bash
   mysql -u <user> -p <db_name> < sql/schema.sql
   ```

4. Create your environment file:
   ```bash
   cp .env.example .env
   ```
   Set at least:
   - `APP_SECRET`
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`

5. Configure your web server to serve the `public/` directory.

6. Create a cron entry to run due checks (every 5–10 minutes is typical):
   ```bash
   php /path/to/certinel/scripts/worker.php --due
   ```

## Usage
1. Open `/public/register.php` and create the first user account.
   - The **first registered user becomes `admin`**.
   - Subsequent registrations default to `viewer` (unless promoted by an admin).

2. In the UI, create one or more **monitors** (host, port, frequency).

3. Configure:
   - expiry warning thresholds
   - notification transports (email/SMTP/HTTP hook)

4. Run checks:
   - from the dashboard: **Check now (all)** / **Quick check**, or
   - via cron worker:
     ```bash
     php scripts/worker.php --due
     ```

5. Review:
   - events timeline
   - historical certificate snapshots
   - optional RSS endpoint for external consumption

## Contributing
- Fork the Repository
- Create a Feature Branch
- Commit Your Changes
- Push to the Branch
- Open a Pull Request

See `CONTRIBUTING.md` for contribution rules and review expectations.

## Contribution Guidelines
- Follow our Code of Conduct (`CODE_OF_CONDUCT.md`)
- Ensure changes do not break existing behaviors (no automated test suite shipped yet)
- Follow project coding standards (keep changes minimal, consistent, and readable)
- Provide clear, concise documentation for new features

## License (Apache 2.0)
This project is licensed under the **Apache License 2.0** (see `LICENSE.md`).

### Key Permissions
- Commercial use
- Modification
- Distribution
- Patent use
- Private use

### Key Conditions
- License and copyright notice
- State changes
- Disclose source

### Limitations
- Trademark use
- Liability
- Warranty

## Contact
- odadroca@acordado.addy.io

## Acknowledgments

- Contributors: 
- Inspirations: Norges Bank - it seems that every time someone sneezes there, a new certificate is issued - *"bless you"*.
- Libraries / tools:
  - PHP (built-in networking + OpenSSL)
  - MySQL / MariaDB
  - Tailwind CSS (CDN build, used for UI styling)
  - Cron (for scheduled worker execution)
