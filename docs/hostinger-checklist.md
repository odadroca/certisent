# Hostinger POC deployment checklist (shared hosting)

## 0) Assumptions
- Apache + PHP 8.x + MySQL available.
- `openssl` and `curl` PHP extensions enabled.
- You can create cron jobs in hPanel.

## 1) Upload
- Create a folder: `public_html/certinel/`
- Upload the project files into it.
- Recommended: access via `https://<domain>/certinel/public/`

## 2) Configure `.env`
- Copy `.env.example` to `.env`
- Set:
  - DB_* credentials
  - APP_URL (example: `https://example.com/certinel/public`)
  - APP_SECRET (long random string)
  - ADMIN_EMAIL
  - API_WORKER_KEY

## 3) Database
- Create a MySQL database and user (hPanel → Databases → MySQL).
- Import `sql/schema.sql` in phpMyAdmin (or via hPanel DB tools).

## 4) First login
- Visit `/certinel/public/`
- Register the first user: it becomes `admin`.

## 5) Cron job (worker)
Create a cron job (every 5–15 minutes):

Command example:
```bash
php /home/<username>/domains/<domain>/public_html/certinel/scripts/worker.php --due
```

Notes:
- Keep cron schedule in UTC (Hostinger UI uses UTC+0).

## 6) Validate
- Add one monitor.
- Trigger manual run:
  - From terminal (if available): `php scripts/worker.php --all --limit=5`
  - Or call API:
    - POST `/certinel/public/api/v1/worker/run` with Bearer token

## 7) RSS
- Each user has an RSS token in DB (`users.rss_token`).
- Feed URL:
  - `/certinel/public/rss.php?token=<rss_token>`

## 8) Hardening (minimum)
- Ensure HTTPS is enabled for the domain.
- Keep `.env` not publicly readable (project includes rewrite-based deny rules).
- Use strong passwords.
