# Deployment and Upgrades (v0.4.2)

## Versioning: app vs DB schema
- **App version** (code release): `app_version()` (shown in Admin → System), e.g. `0.4.2`.
- **DB schema version** (database contract): `schema_version()` and `system_state.schema_version`, e.g. `0.4`.

Patch releases in the `0.4.x` line are expected to keep the **same DB schema** (`system_state.schema_version=0.4`).
Only run SQL migrations when upgrading across releases that change the schema.

## Fresh install
1. Upload the project.
2. Create MySQL database + user.
3. Import `sql/schema.sql`.
4. Copy `.env.example` to `.env` and set DB credentials + `APP_SECRET`.
5. Configure cron to run `php /path/to/scripts/worker.php --due`.

## .env search order
The app searches these paths, in order, and uses the first one found:
1. `<project-root>/.env`
2. `<project-root>/../.env`
3. `<project-root>/public/.env`

If required keys are missing, the app renders a **Configuration missing** page listing missing keys and searched paths.

## Email outbound (v0.4)
Email transport is configured via env.

- Common:
  - `MAIL_FROM`
  - `MAIL_FROM_NAME`
  - `MAIL_TRANSPORT=mail|smtp|api`

- SMTP (`MAIL_TRANSPORT=smtp`):
  - `SMTP_HOST`
  - `SMTP_PORT` (e.g. 465 for SMTPS, 587 for STARTTLS)
  - `SMTP_USER`
  - `SMTP_PASS`
  - `SMTP_ENCRYPTION=ssl|starttls|none`
  - `SMTP_TIMEOUT_SECS` (optional)

- HTTP API hook (`MAIL_TRANSPORT=api`):
  - `MAIL_API_URL`
  - `MAIL_API_TOKEN` (optional)

Use **Admin → Email** to send a test email and validate connectivity.

## Upgrading without dropping tables
Use `sql/migrations/` in order.

Example: v0.3.1 → v0.4
1. Run `sql/migrations/v0.3.1_to_v0.4.sql` once.
2. Confirm `system_state.schema_version` equals `0.4` (schema). Your app version may be `0.4.x`.
3. Visit `/public/admin/system.php` to confirm schema + .env status.
