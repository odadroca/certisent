# Deployment and Upgrades (v0.3.1)

## Fresh install
1. Upload the project.
2. Create MySQL database + user.
3. Import `sql/schema.sql`.
4. Copy `.env.example` to `.env` and set DB credentials + `APP_SECRET`.
5. Configure cron to run `php /path/to/scripts/worker.php --due`.

## .env search order (v0.3.1)
The app searches these paths, in order, and uses the first one found:
1. `<project-root>/.env`
2. `<project-root>/../.env`
3. `<project-root>/public/.env`

If required keys are missing, the app renders a **Configuration missing** page listing missing keys and searched paths.

## Upgrading without dropping tables
Use `sql/migrations/` in order.

Example: v0.2.1 -> v0.3.1
1. Run `sql/migrations/v0.2.1_to_v0.3.sql` once.
2. Run `sql/migrations/v0.3_to_v0.3.1.sql` once.

After migrations, `system_state.schema_version` should equal `0.3.1` and `/admin/system.php` will show schema status.
