# Release notes — v0.5.x

This file tracks security hardening changes introduced across the v0.5.x line.


## v0.5.8 — Baseline security headers (safe defaults)

- Added baseline browser security headers on all HTTP responses:
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `X-Frame-Options: DENY`
- Added CSP support:
  - Default `CSP_MODE=report_only` to avoid UI breakage.
  - Configure policy via `CSP_POLICY`; optional `CSP_REPORT_URI`.
- Added HSTS support:
  - Sent only when HTTPS is confirmed (including proxy mode from v0.5.5).
  - Configurable via `HSTS_ENABLED`, `HSTS_MAX_AGE`, `HSTS_INCLUDE_SUBDOMAINS`, `HSTS_PRELOAD`.


## v0.5.7 — Rate limiting (coarse, defaults high)

- Added coarse rate limiting (defaults are intentionally high):
  - Login: per-IP throttling.
  - API: per-IP and per-Bearer-token throttling for `/api/v1/*`.
- New DB table `rate_limits` (requires migration): `sql/migrations/v0.5.6_to_v0.5.7.sql`.
- Admin → System shows minimal diagnostics: total blocks and recent block keys.
- When limited, the API returns `429` with `{ok:false,error:"rate_limited",scope,...,retry_after}`.


## v0.5.6 — API key ownership + least privilege (opt-in)

- Added optional API key ownership fields (requires DB migration):
  - `api_keys.key_type` (`system` default; `user` for least privilege)
  - `api_keys.owner_user_id` (nullable)
  - Migration: `sql/migrations/v0.5.5_to_v0.5.6.sql`
- Added `API_KEYS_REQUIRE_OWNER=false` (default).
- Enforcement (non-breaking):
  - Existing keys remain `system` scope.
  - For `user` keys, `POST /api/v1/check` with `monitor_id` requires the monitor to be owned by the key owner.

## v0.5.5 — Session/cookie hardening for proxy deployments (opt-in)

- Added `TRUST_PROXY_HEADERS=false` (default).
  - When enabled, the app honors `X-Forwarded-Proto` / `Forwarded` for HTTPS detection.
- Added optional `TRUSTED_PROXY_CIDRS` (comma-separated CIDRs/IPs).
  - If set, proxy headers are trusted only when `REMOTE_ADDR` matches one of these entries.
  - If `TRUST_PROXY_HEADERS=true` and `TRUSTED_PROXY_CIDRS` is empty, any proxy is trusted.
- Added `FORCE_SECURE_COOKIES=false` (default).
  - When enabled, session cookies are always marked `Secure`.

## v0.5.4 — Error page detail reduction (safe default)

- Added `ERROR_DETAIL_MODE=full|safe` (default `safe`).
- In `safe` mode:
  - Unauthenticated config/DB error pages show only a correlation id.
  - Full error details are kept in `app/logs/app.log` keyed by the same correlation id.
- In `full` mode:
  - Config/DB error pages include diagnostic detail (not recommended for public instances).

## v0.5.3 — Admin bootstrap hardening

- Added `REGISTRATION_MODE=open|invite|closed` (default `open`).
- Added optional `SETUP_ADMIN_TOKEN`:
  - When set and the DB has no users yet, the first registration must present this token to claim the first admin.
  - In `REGISTRATION_MODE=invite`, the same token is required for every registration.
- Added optional first-admin email binding using `ADMIN_EMAIL`:
  - On a fresh install (no users yet), only `ADMIN_EMAIL` can claim the first admin (if `ADMIN_EMAIL` is set).
- Added Admin → System control to disable/enable registrations post-setup (DB flag `system_state.registrations_disabled`).
- Audit logging: `admin.registration.toggle`.

## v0.5.2 — RSS tenancy hardening (safe default)

- Non-admin RSS tokens only return events for monitors owned by that user.
- System/global events (`monitor_id IS NULL`) are excluded for non-admin tokens.
- Added `RSS_INCLUDE_SYSTEM_EVENTS=false` (default). When enabled, only `admin`/`auditor` RSS tokens may include system/global events.

## v0.5.1 — Webhook egress hardening (opt-in)

- Added `WEBHOOK_MODE=legacy|public_only|allowlist` (default `legacy`).
- In non-`legacy` modes:
  - Require `https://`
  - Block private/reserved targets unless allowlisted (`allowlist` mode)
  - Prevent redirect-based SSRF bypass (no redirect following)
- Audit logging for webhook URL changes (`user.webhook.update`).

## v0.5.0 — SSRF policy framework (opt-in, default preserves current behavior)

- Added `SSRF_MODE=legacy|public_only|allowlist_private` (default `legacy`).
- Added allowlists: `SSRF_ALLOW_CIDRS`, `SSRF_ALLOW_HOSTS`, `SSRF_ALLOW_PORTS`.
- Enforced for:
  - Monitor certificate fetch targets
  - `/api/v1/check` URL-based checks
