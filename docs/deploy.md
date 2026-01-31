# Deployment and upgrades (v0.5.9)

This document covers (a) fresh installs, (b) common hosting gotchas, and (c) upgrade paths including when SQL migrations are required.

## Apache note: Authorization header forwarding

Some Apache configurations do not pass the `Authorization` header through to PHP by default. If API requests return `401 missing_bearer`, ensure:
- requests are routed through `public/` (document root or `/public/` in the URL),
- `mod_rewrite` is enabled, and
- `public/.htaccess` is being applied (this repo ships a rule that forwards `Authorization`).

## Fresh install

1. Upload the project (or `git clone` it on a VPS).
2. Create a MySQL/MariaDB database and user.
3. Import the schema:

   - `sql/schema.sql`

4. Create `.env`:

   - copy `.env.example` → `.env`
   - set at least: `APP_SECRET`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`

5. Configure your web server to serve the `public/` directory.
6. Configure cron to run the worker regularly:

   - `php /path/to/certinel/scripts/worker.php --due` every 5–15 minutes

## .env search order

The app searches these paths (first match wins):
1. `<project-root>/.env`
2. `<project-root>/../.env`
3. `<project-root>/public/.env`

If required keys are missing, the app renders **Configuration missing** and lists the missing keys and searched paths.

## Upgrade model: code vs DB

- **Code upgrades**: replace the project files; keep your `.env` and database.
- **DB upgrades**: run SQL migrations *only when required for your upgrade path*.

### Schema marker

The app stores a version marker in `system_state` (`key=schema_version`). Current `schema_version()` is `0.4` and is treated as the baseline schema contract.

Important nuance:
- The project may ship **patch migrations** that add tables/columns for hardening while still keeping the baseline `schema_version()` at `0.4`.
- Therefore, *“schema_version matches” does not guarantee you are on the newest patch schema additions*.

## Upgrade paths and required migrations

Before upgrading:
- take a DB backup
- keep a copy of `.env`

### 0.3.1 → 0.4.x / 0.5.x

Run once:
- `sql/migrations/v0.3.1_to_v0.4.sql`

Then upgrade code to your target release.

### 0.5.5 → 0.5.6+

If you are upgrading from <= v0.5.5 and your database was created before v0.5.6, run once:
- `sql/migrations/v0.5.5_to_v0.5.6.sql`

This adds `api_keys.key_type` and `api_keys.owner_user_id`.

If you already have those columns, **do not re-run** the migration.

### 0.5.6 → 0.5.7+

If you are upgrading from <= v0.5.6 and your database predates v0.5.7, run:
- `sql/migrations/v0.5.6_to_v0.5.7.sql`

This creates the `rate_limits` table (idempotent via `CREATE TABLE IF NOT EXISTS`).

## Verifying an upgrade

- Visit **Admin → System** to confirm the worker heartbeat and that the DB is reachable.
- Confirm expected tables exist:
  - `api_keys` includes `key_type` and `owner_user_id` (v0.5.6)
  - `rate_limits` exists (v0.5.7)
- Run the worker once manually and confirm it completes:
  - `php scripts/worker.php --due`

## Email outbound configuration

Email transport is configured via `.env`.

Common:
- `MAIL_FROM`, `MAIL_FROM_NAME`
- `MAIL_TRANSPORT=mail|smtp|api`

SMTP (`MAIL_TRANSPORT=smtp`):
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`
- `SMTP_ENCRYPTION=ssl|starttls|none`
- `SMTP_TIMEOUT_SECS` (optional)

HTTP API relay (`MAIL_TRANSPORT=api`):
- `MAIL_API_URL`
- `MAIL_API_TOKEN` (optional)

Use **Admin → Email** to send a test email.
