<p align="center"><img src="https://i.postimg.cc/qMpPkTVz/certinel-neg.png" alt="Certinel" width="200" height="200"></p>

# Certinel — TLS/SSL Certificate Monitoring (Beta) - v0.7.1

Certinel is a lightweight TLS/SSL certificate monitoring service that **live-fetches** the certificate presented by an endpoint (SNI-capable), stores immutable snapshots, detects changes (renewals/rotations), and notifies before outages happen.

Design goals: simple hosting (shared hosting or VPS), cron-driven checks, and clear observability of **what the endpoint actually serves**.

## Release status
- **Beta (pre-1.0):** expect sharp edges, missing tests, and occasional breaking UI/UX changes between patch releases.
- Data model is intentionally audit-oriented (snapshots + events + audit log).

## What Certinel does
- Monitors `host:port` endpoints and fetches the leaf certificate over TLS (SNI-capable).
- Stores **immutable certificate snapshots** and an **events timeline** (expiry warnings, changes, failures).
- Sends notifications via email/SMTP/API mail relay and optional HTTP hooks.
- Exposes a small HTTP API for remote worker runs and checks (optional).

## What Certinel is not
- Not a CA or CT log monitor; it does not detect certificates issued elsewhere unless your endpoint serves them.
- Not a port scanner; it expects explicit monitors you create.

## Quick start (shared hosting)
1. Upload the project so `/public/` is web-accessible (e.g. `public_html/certinel/`).
2. Copy `.env.example` → `.env`, set at least `APP_SECRET` + DB credentials.
3. Create the MySQL DB + user, then import `sql/schema.sql`.
4. Visit `/public/register.php` and create the first user (first registered user becomes **admin**).
5. Add monitors in the dashboard.
6. Add cron: `php /path/to/certinel/scripts/worker.php --due` every 5–15 minutes.

Docs: `docs/deploy.md`, `docs/ops_runbook.md`.

## Configuration essentials (.env)
- Required: `APP_SECRET`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
- Outbound email: `MAIL_TRANSPORT` (`mail|smtp|api`) plus `MAIL_FROM`/`MAIL_FROM_NAME`.
- Security hardening knobs (v0.5.x): `SSRF_MODE`, `WEBHOOK_MODE`, `ERROR_DETAIL_MODE`, proxy/cookie settings, rate limiting, and baseline security headers. See `.env.example` and `docs/architecture.md`.

## Operations model
- **Worker**: cron-driven (`scripts/worker.php --due`) checks monitors that are due by frequency, records snapshots/events, and enqueues notifications.
- **Outbox**: notification deliveries are queued and retried (Admin → Outbox).
- **Heartbeat**: Admin → System shows last worker run time (UTC).
- **Upgrade approach**: replace code, keep `.env` + DB; run SQL migrations only when required (see `docs/deploy.md`).

## Security baseline (documented behaviors)
- Password hashing (`password_hash` / `password_verify`)
- CSRF tokens on mutating forms
- Logout (v0.6): UI uses POST+CSRF; direct GET requests show a confirmation page.
- Prepared statements (PDO)
- Role checks on every action
- Session hardening (`SameSite`, `HttpOnly`, `Secure` when HTTPS; proxy-aware options in v0.5.5)
  - Reverse-proxy support: `TRUST_PROXY_HEADERS` + `TRUSTED_PROXY_CIDRS` + `FORCE_SECURE_COOKIES` (v0.5.5)

Security reporting: see `SECURITY.md`.

## Documentation index
- `docs/deploy.md` — deployment + upgrade paths
- `docs/ops_runbook.md` — operational troubleshooting
- `docs/architecture.md` — component/data-flow overview
- `docs/api.md` — API endpoints/scopes
- `docs/ui_map.md` — UI routes by role
- `docs/release_notes_v0.5.x.md` — v0.5 hardening changes
- `docs/release_notes_v0.6.x.md` — v0.6+ patch notes
- `docs/localization.md` — localization (i18n) guide
- `docs/release_notes_v0.7.x.md` — v0.7 release notes

### Localization formatting
- `I18N_FORMAT_DATES` (default `false`): enable locale-aware UI formatting for dates/numbers (requires PHP intl).


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
- Inspirations: Norges Bank - *it seems that every time someone sneezes there, a new certificate is issued.*
- Libraries / tools:
  - PHP (built-in networking + OpenSSL)
  - MySQL / MariaDB
  - Tailwind CSS (CDN build, used for UI styling)
  - Cron (for scheduled worker execution)
