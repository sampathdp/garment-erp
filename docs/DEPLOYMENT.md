# Deployment — garment-erp.codeplaystudios.com

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
