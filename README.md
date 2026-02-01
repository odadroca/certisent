<p align="center"><img src="https://i.postimg.cc/qMpPkTVz/certinel-neg.png" alt="Certinel" width="200" height="200"></p>

# Certinel — TLS/SSL Certificate Monitoring (Beta) - v0.7.6

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

## TLS validation mode (hostname + trust) (v0.7.6)

Certinel **does not verify TLS** by default (`verify_peer=false`) because it is designed to observe *what the endpoint actually serves*.

v0.7.2 introduced an **opt-in** per-monitor `tls_validation_mode` data model. In v0.7.3+ it can persist:
- **Hostname identity** ("wrong.host" style): `monitors.hostname_ok` / `monitors.hostname_error`.
- **Trust (chain validation)**: `monitors.trust_ok` / `monitors.trust_category` / `monitors.trust_error`.

Trust validation uses a *separate* probe that validates the certificate chain using the system CA bundle. Hostname verification is handled separately (it is not part of the trust probe).

Mode values:
- `off` (default): legacy behavior; hostname/trust validation not computed or persisted.
- `observe`: compute and persist hostname + trust validation fields (does not change expiry/change alerting behavior).
- `enforce`: currently behaves like `observe` (compute + persist + notify). Enforcement is reserved for future behavior changes.

Trust categories:
- `tls_self_signed` — self-signed certificate.
- `tls_untrusted_root` — chain cannot be validated to a trusted root (system CA bundle).
- `tls_untrusted_unknown` — trust validation failed but could not be classified.

Quick Check shows immediate warnings for hostname mismatch and trust validation failures.

When `tls_validation_mode` is `observe` or `enforce`, the worker also emits new event types (notification categories) when invalid states are detected:
- `tls_wrong_host` — hostname mismatch (certificate names do not match the requested host).
- `tls_self_signed` — self-signed certificate (not trusted by the system CA bundle).
- `tls_untrusted_root` — chain cannot be validated to a trusted root (system CA bundle).

Event dedupe: these TLS invalid-state events are created only when the classification changes or when the certificate fingerprint changes.


UI (v0.7.6):
- Monitor → Edit → **TLS validation mode** (`off|observe|enforce`).
- Dashboard and Monitor view show the last-known hostname/trust validation summary (separate from expiry/change status).

Safe usage for internal/self-signed environments:
- Keep `off` unless you explicitly want trust/identity warnings.
- If you enable `observe|enforce` on internal/self-signed services, expect `tls_self_signed` / `tls_untrusted_root` classifications and events.

Enable for a monitor (SQL alternative):
- set `monitor_settings.tls_validation_mode='observe'` (or `enforce`) for the target `monitor_id`.

Optional configuration (env):
- `TLS_TRUST_CONNECT_TIMEOUT_SECS` (default `4`) — connect timeout for trust probe.
- `TLS_TRUST_TIMEOUT_SECS` (default `6`) — total timeout for trust probe.
- `TLS_CA_BUNDLE` (default empty) — optional CA bundle path override when the system CA bundle is not available.

## Certificate/public-key pinning (SPKI sha256) (v0.7.6)

Certinel supports **Certinel-defined pinning** (not HPKP preload).

- Pin material: **SPKI sha256** of the *leaf certificate public key* (`base64(SHA-256(SPKI DER))`).
- Purpose: detect unexpected key changes (interception, misconfiguration, unplanned rotation).

Per-monitor settings (stored in `monitor_settings`):
- `pin_mode`: `off|observe|enforce` (default `off`).
- `pin_spki_sha256`: base64 sha256 of SPKI (no prefix) — UI accepts `sha256/<base64>` too.

Behavior:
- Quick Check displays the current `SPKI sha256 (pin)` so you can copy/paste it.
- When `pin_mode` is `observe|enforce` and a pin is set, the worker emits `tls_pin_mismatch` if the observed SPKI sha256 differs.
  - Severity: `warn` in `observe`, `critical` in `enforce`.
- Pinning does **not** change fetch behavior (`verify_peer=false`) and does not affect existing expiry/change/renewal logic unless enabled.

Safe usage:
- Keep `off` unless you explicitly want to be alerted on key mismatches.
- Planned rotations require updating the pin value.

Enable for a monitor (SQL alternative):
- set `monitor_settings.pin_mode='observe'` (or `enforce`) and set `monitor_settings.pin_spki_sha256` to the base64 pin value.

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
