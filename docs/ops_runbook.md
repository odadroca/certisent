# Ops Runbook (v0.4.7)

## Common failure: 500 due to missing/misplaced `.env`
Symptoms:
- UI shows **Configuration missing** and lists missing keys.

Fix:
- Ensure `.env` exists at `<project-root>/.env` (preferred).
- Confirm required keys exist: `APP_SECRET`, `DB_NAME`, `DB_USER`, `DB_PASS`.

## Common failure: database unavailable
Symptoms:
- UI shows **Database unavailable** with a Ref id.

Fix:
- Confirm DB host/name/user/pass in `.env`.
- Confirm the DB server is reachable from the hosting environment.

## Logs
- App logs (best-effort): `app/logs/app.log`
- If `app/logs/app.log` is empty, ensure the `app/logs/` directory exists and is writable by the PHP user.


## Correlation IDs
- Worker runs (`--due`) and async job processing emit a short correlation id into the **server error log**.
- Log line format (example):
  - `[certinel] cid=1a2b3c4d5e6f7a8b worker_due_start {"limit":null}`
- Use the same `cid=...` to correlate the start/end lines for a single execution.
- Correlation IDs are **not** stored in the database and are not shown to unauthenticated users.

## Cron
- Recommended: run `php /path/to/scripts/worker.php --due` every 5–10 minutes.
- If checks show `checked:0`, monitors may not be due yet (their frequency may be 60 minutes by default).


## Common failure: API returns 401 missing_bearer under Apache
Symptoms:
- API calls with `Authorization: Bearer <token>` return `401 missing_bearer`.

Likely cause:
- Apache did not forward the `Authorization` header to PHP.

Fix:
- Ensure requests are routed through `public/` and that `mod_rewrite` is enabled.
- Confirm `public/.htaccess` includes a rule to forward `Authorization` to `HTTP_AUTHORIZATION`.

## Upgrades: app vs DB schema
See `docs/deploy.md` for the app version vs DB schema version contract and patch upgrade rules.
