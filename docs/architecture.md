# Architecture (v0.5.9)

## Components

- **Web UI**: PHP pages under `public/` (Tailwind via CDN build).
- **Database**: MySQL/MariaDB persistence for users, monitors, snapshots, events, audit log, API keys, outbox, worker jobs, rate limits.
- **Worker**: CLI script (`scripts/worker.php`) typically invoked by cron.
- **Outbox processing**: queued notification deliveries with retries (email and webhooks).
- **HTTP API**: `/api/v1/*` for remote worker runs and checks (optional).
- **RSS**: tokenized feed endpoint for events.

## Data model (high level)

- `monitors`: what to check (host/port) + ownership.
- `cert_snapshots`: immutable certificate captures per monitor.
- `events`: derived events (expiry warnings, fingerprint changes, failures).
- `notification_outbox`: pending/sent/failed deliveries with retry metadata.
- `audit_log`: user actions.
- `api_keys`: Bearer tokens (hashed), scoped; optionally user-owned (v0.5.6).
- `rate_limits`: coarse per-IP/per-token counters (v0.5.7).

## Execution flow

1. **Scheduling**
   - Cron invokes `scripts/worker.php --due`.
   - Worker selects monitors that are due by frequency.

2. **Fetch and parse**
   - Worker performs a live TLS handshake to the target (SNI-capable) and parses the served certificate.

3. **Persist**
   - A new snapshot is stored (immutable).
   - Events are emitted based on comparisons to the previous snapshot (fingerprint change, renewal/rotation, approaching expiry, failures).

4. **Notify**
   - Notification deliveries are enqueued into the outbox.
   - Outbox processing sends email/webhooks and applies retry/backoff.

5. **Operator observability**
   - Admin → System shows last worker heartbeat and operational summaries (UTC).

## Trust boundaries and policy controls

Certinel *actively connects to remote endpoints*, which is an SSRF-shaped surface.

Controls are configured via `.env`:
- **Target SSRF policy** (`SSRF_MODE`):
  - `legacy` (default): preserves older behavior (no blocking)
  - `public_only`: blocks private/reserved ranges
  - `allowlist_private`: blocks private/reserved unless allowlisted (`SSRF_ALLOW_*`)
- **Webhook egress policy** (`WEBHOOK_MODE`):
  - `legacy` (default): preserves older behavior
  - `public_only`: requires HTTPS and blocks private/reserved ranges
  - `allowlist`: requires HTTPS and allows private/reserved only via allowlists
- **Error detail policy** (`ERROR_DETAIL_MODE`): defaults to redacting unauthenticated error details.
- **Rate limiting** (v0.5.7): coarse limits for login and API calls.

Failure modes:
- Leaving SSRF/webhook modes in `legacy` on an internet-exposed instance increases SSRF blast radius.
- Over-broad allowlists recreate SSRF risk (internal metadata services, private admin UIs).

## Integrity and change auditing

- Snapshots are append-only (immutable history).
- Audit log records user actions.
- `docs/file_registry.md` can be used as an integrity reference (hash inventory) for deployments that want change detection at the file level.
