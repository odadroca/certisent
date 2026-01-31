# API (v0.6)

Authentication:
- Preferred: create an API key in the admin UI (Admin → API Keys) and use `Authorization: Bearer <token>`.
- Legacy fallback: `.env` `API_WORKER_KEY` is still accepted and is treated as full-scope (`*`) for upgrade safety.

Base path: `/api/v1/` (if your web root is `public/`) or `/public/api/v1/` (if you serve the project root and keep `public/` in the URL).

## Scopes

- `run_worker`: POST `/api/v1/worker/run`
- `check_monitor`: POST `/api/v1/check`
- `read_health`: GET `/api/v1/health` (requires `read_health`; `run_worker` is also accepted for backward compatibility)


## Apache note: Authorization header forwarding


See `docs/deploy.md` for deployment/upgrade context.

Some Apache configurations do not pass the `Authorization` header through to PHP by default, which can cause `401 missing_bearer` even when your client sends the header.

This release forwards `Authorization` in `public/.htaccess`. If you still see `missing_bearer`, verify that:
- `mod_rewrite` is enabled, and
- requests are hitting the `public/` folder (document root or `/public/` in the URL).

In some hosting environments the header may surface as `REDIRECT_HTTP_AUTHORIZATION` or only via `getallheaders()`; v0.4.8 expands Bearer token extraction to cover these common variants.

## Endpoints

### GET /api/v1/health
Returns server time and last worker heartbeat.

Response:
```json
{ "ok": true, "time_utc": "2026-01-24 12:00:00", "last_cron_run_at": "..." }
```

Example:
```bash
curl -s \
  -H 'Authorization: Bearer <token>' \
  https://example.com/certinel/public/api/v1/health
```

Note: For API keys stored in `api_keys`, the recommended scope is `read_health`. For backward compatibility, `run_worker` also grants access to this endpoint.


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

Notes (v0.5 SSRF policy):
- In non-`legacy` SSRF modes, URL-based quick checks may be rejected with `ssrf_blocked: <reason>`.

Notes (v0.5.6 API key ownership):
- If the Bearer token corresponds to a **user-scoped** API key, `POST /api/v1/check` with `monitor_id` enforces monitor ownership.
- Possible errors: `api_key_owner_required`, `forbidden_monitor`.

Notes (v0.5.7 rate limiting):
- If request volume is excessive, the API returns `429`:
  ```json
  {"ok":false,"error":"rate_limited","scope":"api_ip","retry_after":60}
  ```
  `scope` is `api_ip` or `api_token`. Limits are configurable via `RATE_LIMIT_*` env vars.

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
