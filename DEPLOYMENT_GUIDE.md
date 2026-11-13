# Deployment Guide — garment-erp.codeplaystudios.com

Shared hosting (profile A, ARCHITECTURE.md §5). One subdomain serves both apps:

| Path | Served by |
|---|---|
| `/`, `/orders/`, … | Static Next.js export (`frontend/out`) |
| `/api/*`, `/sanctum/*`, `/up` | Laravel (`backend/public/index.php`) |
| any other `/backend/*` | 403 (root `.htaccess`) |

Server layout (subdomain document root = the FTP account's root):

```
<home>/
├── envs/garment-erp.env          production settings (chmod 600) — NOT in the web root
├── garment-erp-documents/        uploaded files (DOCUMENTS_ROOT) — NOT in the web root
└── garment-erp.codeplaystudios.com/   (or public_html/garment-erp…)
    ├── .htaccess  index.html  _next/  …   frontend (deploy-frontend job)
    └── backend/                           Laravel incl. vendor/ (deploy-backend job)
```

`backend/bootstrap/app.php` loads `envs/garment-erp.env` from two or three levels above `backend/`, so both
document-root layouts work. If that file is missing, Laravel falls back to `backend/.env`.

## 1. Hosting requirements

- PHP 8.2+ (8.3 recommended) with `intl`, `sodium`, `pdo_mysql`, `mbstring`, `fileinfo`, `bcmath`, `gd`, `zip`; `proc_open` enabled (the scheduler runs commands in the background).
- MySQL 8 (MariaDB 10.6+ works).
- SSH or cPanel Terminal (to run `artisan`), and cron at 1-minute intervals.
- Free TLS on the subdomain.

## 2. One-time server setup

1. **Subdomain:** create `garment-erp.codeplaystudios.com` in cPanel, enable AutoSSL/Let's Encrypt, select PHP 8.3.
2. **Database:** cPanel → MySQL Databases: create the database and a user, then add the user to the database with **ALL PRIVILEGES** (the audit migration needs `TRIGGER`).
3. **FTP account** whose root is the subdomain's document root.
4. **Folders outside the web root:**
   ```sh
   mkdir -p ~/envs ~/garment-erp-documents
   chmod 700 ~/envs ~/garment-erp-documents
   ```
5. **Settings:** copy `backend/.env.production.example` to `~/envs/garment-erp.env`, fill in `DB_*`, `DOCUMENTS_ROOT` (absolute path of `~/garment-erp-documents`), `MAIL_*` and the `SEED_*` values, then `chmod 600 ~/envs/garment-erp.env`.
6. **GitHub secrets** (repo → Settings → Secrets and variables → Actions): `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
7. **First deploy:** push to `master` (deploys after CI passes) or run *Deploy to Shared Hosting* manually from the Actions tab.
8. **Key, migrations, first data** (SSH / cPanel Terminal, in the uploaded `backend/` folder):
   ```sh
   cd ~/garment-erp.codeplaystudios.com/backend
   php artisan key:generate --show          # paste the value into APP_KEY in ~/envs/garment-erp.env
   php artisan migrate --force
   php artisan db:seed --class=ProductionBootstrapSeeder --force
   php artisan app:security-check
   ```
   `ProductionBootstrapSeeder` runs `PermissionSeeder` and `CatalogSeeder`, creates the organization
   (`SEED_ORG_NAME`), a Monday–Saturday working week and one Super Admin (`SEED_ADMIN_EMAIL`). With
   `SEED_ADMIN_PASSWORD` empty it prints a random password **once**. Then remove the `SEED_*` lines from
   the settings file, sign in, change the password, and create branches, pages, users, sizes, fabrics and prices in the app.
   Never run the plain `db:seed` in production (it refuses to).
9. **Cron** (cPanel → Cron Jobs, every minute):
   ```
   * * * * * cd /home/<user>/garment-erp.codeplaystudios.com/backend && php artisan schedule:run >> /dev/null 2>&1
   ```
   Use the full path of the PHP 8.3 binary if `php` on the CLI is a different version (`/usr/local/bin/ea-php83` on cPanel EasyApache).
   This drains the database queue and sweeps the notification outbox.

## 3. Every deploy

The workflow uploads code only. After a deploy that adds migrations or permissions:

```sh
cd ~/garment-erp.codeplaystudios.com/backend
php artisan down --retry=60          # optional, for migrations that change busy tables
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force     # when new permissions were added
php artisan db:seed --class=CatalogSeeder --force        # when new reference data was added
php artisan up
php artisan app:security-check
```

Config is not cached (`config:cache` would freeze the settings file until the next manual `config:clear`).
If you do cache it, run `php artisan optimize:clear && php artisan config:cache` after every deploy and settings change.

## 4. Troubleshooting

- **`You do not have the SUPER privilege and binary logging is enabled`** during `migrate`: the host forbids triggers. Set `AUDIT_DB_TRIGGERS=false` (audit rows stay append-only in the model), drop the half-created table (`php artisan tinker` → `Schema::drop('audit_logs')`), and run `migrate --force` again.
- **419 / 401 right after login:** `SANCTUM_STATEFUL_DOMAINS` must be exactly `garment-erp.codeplaystudios.com`, `SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=true`, and the site must be opened over https.
- **500 with no log line:** `storage/` or `bootstrap/cache/` are not writable by PHP — `chmod -R 775 backend/storage backend/bootstrap/cache`.
- **API returns the 404 page:** `mod_rewrite` is off, or `.htaccess` is missing from the document root (it comes from `frontend/public/.htaccess`).
- **Health check:** `https://garment-erp.codeplaystudios.com/api/v1/health`.

## 5. Moving local data

Production starts from an empty MySQL database (steps above). Do not copy development data to the server.

## 6. Phase 09 deploy (hardening release)

1. **Back up first** (BACKUP_AND_RECOVERY.md §2): database dump + documents folder.
2. Deploy the code (workflow), then on the server:
   ```sh
   cd ~/garment-erp.codeplaystudios.com/backend
   php artisan down --retry=60
   php artisan migrate --force      # 2026_11_17_000001_add_phase09_hardening_indexes
   php artisan up
   php artisan app:security-check
   ```
   The migration adds `payments.page_id` and backfills it from the orders in chunks of 1,000 (a few seconds per 100k payments), then adds five indexes and drops two redundant ones. On MySQL 8 the index builds are online; keep maintenance mode on anyway, because the backfill updates every payment row. No new permissions: the seeders do not need to run.
3. Verify: `curl -s https://garment-erp.codeplaystudios.com/api/v1/health` → `"status":"ok"`, and after the next cron minute `"scheduler":"ok"`.

## 7. Monitoring

- **Uptime check** (any external monitor, every 5 minutes) on `GET /api/v1/health`. It answers 503 when the database or cache fails **or when the scheduler heartbeat is older than 5 minutes** (`checks.scheduler = stale`): on shared hosting that means the cron entry stopped and no queued job or notification is being processed. `unknown` means the scheduler never ran (fresh install).
- **Logs**: `backend/storage/logs/` (JSON lines, `LOG_STACK=json`), `slow_queries` channel for queries over `DB_SLOW_QUERY_MS`. Download or tail them through cPanel; there is no error-tracking service yet (KNOWN_LIMITATIONS.md).
- **Dead letters**: notifications that failed 8 times are logged at `error` level (`Notification outbox row gave up after retries`); failed queue jobs are in `failed_jobs` (`php artisan queue:failed`, pruned after 30 days).

## 8. Rollback

The workflow uploads files in place (`mirror --only-newer`, no release folders), so a rollback is a redeploy of the previous commit:

1. `php artisan down`.
2. If the release added migrations, roll them back **before** the old code goes up: `php artisan migrate:rollback --step=<n> --force` (every migration has a working `down()`; for Phase 09, `--step=1` drops `payments.page_id` and restores the old indexes). Restore the database dump instead when a migration changed data irreversibly.
3. Re-run *Deploy to Shared Hosting* from the Actions tab on the previous commit (`workflow_dispatch`), or push a revert commit.
4. `php artisan optimize:clear`, `php artisan up`, check `/api/v1/health`.

Files removed in a newer release stay on the server (no `--delete`); they are harmless because routes and providers are explicit, but clean them up when the backend folder structure changes.

## 9. Open deployment risks

- **FTPS without certificate verification** (`set ssl:verify-certificate no` in `.github/workflows/deploy.yml`): the FTP password and the uploaded code can be intercepted or altered by anyone on the path. Fix: get the host's FTP certificate chain (or the server hostname with a valid certificate), set `ssl:verify-certificate yes` (and `ssl:check-hostname yes`), or switch to SFTP with a deploy key (SECURITY_STANDARDS.md §7). Not changed in Phase 09 because it cannot be tested from here and a wrong setting stops every deploy.
- No staging environment: test migrations on a copy of the production dump first (BACKUP_AND_RECOVERY.md §4).
