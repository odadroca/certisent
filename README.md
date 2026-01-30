# Certinel — the certificate sentinel — v.0.4.1
Certinel is a lightweight TLS/SSL certificate monitoring service that **live-fetches** the certificate presented by an endpoint (SNI-capable), stores immutable snapshots, detects changes (renewals/rotations), and notifies interested parties before outages happen.

It is designed to be simple to host (shared hosting or VPS), easy to operate (cron-driven worker), and explicit about what it is observing: **the certificate the endpoint actually serves**.

## v0.4 highlights
- UI theme updated (background `#0b2840`, accent `#09d2e8`).
- Email outbound supports `MAIL_TRANSPORT=mail|smtp|api` (SMTP without external dependencies).
- Dashboard “Check now (all)” runs via an async job, with progress + cancel controls on Admin → System.
- Operator controls: Run outbox now, cancel jobs, view job history.

## Notes / limitations (v0.4)
- Notifications: email (PHP `mail()`) + optional Slack/Teams webhooks (basic POST). RSS is read-only.
- “False positives” are mitigated with a confirm re-check on certificate change (configurable sample count).
- No background job queue; worker sends notifications inline.

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
- Inspirations: Norges Bank - it seems that every time someone sneezes there, a new certificate is issued.
- Libraries / tools:
  - PHP (built-in networking + OpenSSL)
  - MySQL / MariaDB
  - Tailwind CSS (CDN build, used for UI styling)
  - Cron (for scheduled worker execution)
