# Ops Runbook (v0.3.1)

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

## Cron
- Recommended: run `php /path/to/scripts/worker.php --due` every 5–10 minutes.
- If checks show `checked:0`, monitors may not be due yet (their frequency may be 60 minutes by default).
