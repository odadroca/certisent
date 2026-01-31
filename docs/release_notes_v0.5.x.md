# Release notes — v0.5.x

This file tracks security hardening changes introduced across the v0.5.x line.

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
