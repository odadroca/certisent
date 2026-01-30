# API (v0)

Authentication: `Authorization: Bearer <API_WORKER_KEY>` (shared secret from `.env`).

Base path: `/public/api/v1/` (unless you set document root to `public/`).

## Apache note: Authorization header forwarding

Some Apache configurations do not pass the `Authorization` header through to PHP by default, which can cause `401 missing_bearer` even when your client sends the header.

This release forwards `Authorization` in `public/.htaccess`. If you still see `missing_bearer`, verify that:
- `mod_rewrite` is enabled, and
- requests are hitting the `public/` folder (document root or `/public/` in the URL).

## Endpoints

### GET /api/v1/health
Returns server time and last worker heartbeat.

Response:
```json
{ "ok": true, "time_utc": "2026-01-24 12:00:00", "last_cron_run_at": "..." }
```

### POST /api/v1/worker/run
Runs the worker.

Body:
```json
{ "mode": "due", "limit": 50 }
```

- `mode`: `due` (default) checks only monitors due by frequency; `all` checks all enabled monitors.
- `limit`: optional int.

Response:
```json
{ "ok": true, "result": { "checked": 10, "errors": 0, "changed": 1, "renewed": 1, "warned": 2 } }
```

### POST /api/v1/check
Either checks a stored monitor (and stores a new snapshot + events), or does a stateless quick-check.

Body (stored monitor):
```json
{ "monitor_id": 123 }
```

Body (quick check):
```json
{ "url": "https://api.example.com" }
```

Quick-check response includes parsed certificate summary (no DB write).

## Status vocabulary used by the system
- `ok`: certificate valid; days_remaining > notify threshold
- `warn`: certificate valid but within notify threshold
- `critical`: expired, or fetch failure
- `unknown`: not enough data

## Examples

Run due checks:
```bash
curl -s -X POST \
  -H 'Authorization: Bearer <API_WORKER_KEY>' \
  -H 'Content-Type: application/json' \
  -d '{"mode":"due"}' \
  https://example.com/certinel/public/api/v1/worker/run
```
