# Ops runbook (v0.5.9)

This is a pragmatic troubleshooting checklist for operators running Certinel on shared hosting or a VPS.

## Common failure: configuration missing (500 / config page)

Symptoms:
- UI shows **Configuration missing** and lists missing keys and searched `.env` locations.

Fix:
- Ensure `.env` exists at `<project-root>/.env` (preferred).
- Confirm required keys exist: `APP_SECRET`, `DB_NAME`, `DB_USER`, `DB_PASS` (and `DB_HOST` if non-default).
- Confirm PHP can read the file (permissions).

## Common failure: database unavailable

Symptoms:
- UI shows **Database unavailable** with a correlation/reference id.

Fix:
- Confirm DB host/name/user/pass in `.env`.
- Confirm the DB server is reachable from the hosting environment.
- Confirm `sql/schema.sql` has been imported (fresh install).

## Logs and correlation ids

- App log (best-effort): `app/logs/app.log`
  - Ensure `app/logs/` exists and is writable by the PHP user.
- Server error log is still the primary place for fatal errors.
- Unauthenticated error pages default to redacted output (`ERROR_DETAIL_MODE=safe`) and show a correlation id; use logs to find matching entries.

## Cron and worker heartbeat

Recommended schedule:
- `php /path/to/certinel/scripts/worker.php --due` every 5–15 minutes.

If checks show `checked: 0`:
- monitors may not be due yet (frequency settings)
- or cron is not running from the expected path / PHP binary

Admin → System:
- shows last worker run time (UTC) and staleness indicators.

## API returns 401 missing_bearer (Apache)

Symptoms:
- API calls with `Authorization: Bearer <token>` return `401 missing_bearer`.

Cause:
- Apache did not forward the `Authorization` header to PHP.

Fix:
- Ensure requests are routed through `public/` and that `mod_rewrite` is enabled.
- Confirm `public/.htaccess` is applied and forwards `Authorization`.

## API returns 429 rate_limited

Symptoms:
- API returns `{"ok":false,"error":"rate_limited", ...}`.

Fix / options:
- Reduce polling volume from callers.
- Increase limits via `.env`:
  - `RATE_LIMIT_API_IP_*`
  - `RATE_LIMIT_API_TOKEN_*`
- If you truly need it: disable by setting max/window to `<=0` (accept the abuse risk).

Failure mode:
- Disabling limits on a public instance makes brute-force and abuse cheaper.

## Quick check / monitor check blocked: ssrf_blocked

Symptoms:
- UI quick check or API `POST /api/v1/check` returns `ssrf_blocked:<reason>`.

Cause:
- `SSRF_MODE` is set to a non-`legacy` mode and the target resolves to a private/reserved range or fails policy checks.

Fix:
- If you intentionally monitor private infrastructure, use `SSRF_MODE=allowlist_private` and set:
  - `SSRF_ALLOW_CIDRS`
  - `SSRF_ALLOW_HOSTS`
  - `SSRF_ALLOW_PORTS`

Failure mode:
- Over-broad allowlists recreate SSRF risk (internal metadata services, admin panels, etc.).

## Webhook delivery blocked

Symptoms:
- Outbox shows webhook failures mentioning policy or blocked target.

Cause:
- `WEBHOOK_MODE` is set to a hardened mode and the destination violates policy (e.g., non-HTTPS or private IP without allowlist).

Fix:
- Prefer HTTPS endpoints.
- If you must deliver to private endpoints, use `WEBHOOK_MODE=allowlist` and set `SSRF_ALLOW_*` allowlists.

## Reverse-proxy deployments: login loops / cookies not secure

Symptoms:
- Sessions fail to persist, or cookies are not marked `Secure` behind a TLS-terminating proxy.

Fix:
- Set:
  - `TRUST_PROXY_HEADERS=true`
  - `TRUSTED_PROXY_CIDRS=<your proxy cidrs>` (recommended)
  - `FORCE_SECURE_COOKIES=true` (only if you cannot make HTTPS detection reliable)

Failure modes:
- Trusting arbitrary proxy headers without CIDR gating enables spoofing.

## Logout behavior (v0.5.9)

- `GET /logout.php` shows a confirmation page.
- Actual logout is `POST /logout.php` with CSRF token.

If a monitoring tool or bookmark expects GET to log out, update it to use POST (browser UI already does).
