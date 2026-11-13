# Backup and Recovery

Written for whoever operates the production server (shared hosting, profile A). Nothing in this document runs automatically yet: the application has **no built-in backup job**. Until the steps in §2 are scheduled, the only copy of the data is the host's own backup, which SECURITY_STANDARDS.md §7 and the roadmap (Phase 15) say is not enough.

## 1. What must be backed up

| Data | Where | Why it matters | Target |
|---|---|---|---|
| MySQL database | the cPanel database (`DB_DATABASE`) | Orders, payments, invoices, lists, audit log — everything | RPO 24 h (daily dump), keep 30 daily + 12 monthly |
| Uploaded documents | `DOCUMENTS_ROOT` (e.g. `~/garment-erp-documents`) | Payment slips, design files, samples, gate passes, delivery proof. The database only stores their paths and SHA-256 | Same schedule as the database (a dump without its files is incomplete) |
| Settings file | `~/envs/garment-erp.env` | `APP_KEY` decrypts sessions and encrypted cookies; DB and mail credentials | Copy once into a password manager; again after every change |
| Code | GitHub | Redeployable from any commit | — |

Not needed: `backend/storage/framework` (cache, compiled views), `sessions`/`cache`/`jobs` tables (transient; a restore logs everyone out, which is fine).

`APP_KEY` must be the **same** after a restore. With a new key, existing sessions and any encrypted values become unreadable; there are no encrypted database columns today, so only sign-ins are affected.

## 2. Backup procedure (daily, cron)

Create `~/bin/garment-erp-backup.sh` (chmod 700) and schedule it once a day, outside working hours (Asia/Colombo), in cPanel → Cron Jobs:

```sh
#!/bin/sh
set -eu
umask 077
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DEST="$HOME/backups/garment-erp"
mkdir -p "$DEST"

# 1. Database: consistent InnoDB snapshot without locking the tables. Credentials in ~/.my.cnf (chmod 600),
#    never on the command line. --triggers keeps the audit_logs append-only triggers.
mysqldump --defaults-extra-file="$HOME/.my.cnf" --single-transaction --quick --routines --triggers \
  --no-tablespaces --set-gtid-purged=OFF garment_erp_db | gzip -9 > "$DEST/db-$STAMP.sql.gz"
#    (--set-gtid-purged is MySQL-only; remove it on MariaDB.)

# 2. Documents (private uploads).
tar -C "$HOME" -czf "$DEST/documents-$STAMP.tar.gz" garment-erp-documents

# 3. Checksums, so a restore can prove the files are intact.
( cd "$DEST" && sha256sum "db-$STAMP.sql.gz" "documents-$STAMP.tar.gz" > "SHA256SUMS-$STAMP" )

# 4. Local retention: 7 days on the server (the off-site copy keeps the long history).
find "$DEST" -type f -mtime +7 -delete
```

**Off-site copy (required):** a backup on the same account dies with the account. Copy each night's files to storage the hosting account cannot delete, encrypted before they leave the server, for example `age -r <public key>` or `gpg --encrypt -r <key>`, then `rclone copy` to S3-compatible storage, Google Drive or another server. Keep the private decryption key off the server (password manager / offline). The off-site copy holds 30 daily and 12 monthly backups.

**Alerting:** send the cron output to an e-mail address (cPanel "Cron Email") so a failing dump is noticed, and check the off-site bucket weekly.

## 3. Restore procedure

Use it for a lost/corrupted database, a bad migration that cannot be rolled back, or a move to a new host.

1. Maintenance mode: `cd ~/garment-erp.codeplaystudios.com/backend && php artisan down`.
2. Verify the backup: `sha256sum -c SHA256SUMS-<stamp>` (after decrypting).
3. Database — restore into an **empty** database (never over a live one):
   ```sh
   mysql --defaults-extra-file=~/.my.cnf -e 'DROP DATABASE IF EXISTS garment_erp_db; CREATE DATABASE garment_erp_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
   gunzip -c db-<stamp>.sql.gz | mysql --defaults-extra-file=~/.my.cnf garment_erp_db
   ```
   If the host forbids triggers (`SUPER privilege` error), strip the two `audit_logs` triggers from the dump and set `AUDIT_DB_TRIGGERS=false` (DEPLOYMENT_GUIDE.md §4); the model still refuses updates and deletes.
4. Documents: `tar -C ~ -xzf documents-<stamp>.tar.gz`, then `chmod -R go-rwx ~/garment-erp-documents`.
5. Code: deploy the commit that matches the backup's schema (`SELECT MAX(migration) FROM migrations` tells you which migrations it has); run `php artisan migrate --force` only if the code is newer.
6. Check: `php artisan app:security-check`, `php artisan up`, `GET /api/v1/health` = ok, sign in, open a recent order and download one of its documents.
7. Record the incident (what was lost between the backup time and the failure; re-enter it from paper or WhatsApp records).

Data written after the last backup is lost (RPO). Payments verified in that window must be re-verified against the bank statement.

## 4. Restore drill (quarterly)

SECURITY_STANDARDS.md §7 requires a tested restore every quarter. Restore the latest off-site backup into a **separate** database (and a scratch documents folder), point a local or staging copy of the app at it, and run the step 6 checks. Note how long the restore took (RTO target: under 4 hours). The same restored copy is the safe place to rehearse a risky migration before running it in production.

## 5. Point-in-time and partial recovery

Shared hosting gives no binary-log access, so point-in-time recovery is not possible; the daily dump is the recovery point. Business data is never deleted by the application (cancel/void instead; FKs `RESTRICT`), and the audit log records who changed what, so most mistakes are corrected in the app rather than by restoring. To recover one record, restore the dump into a scratch database and copy the rows across by hand, with an audit note.

## 6. Status

| Item | Status |
|---|---|
| Backup script and schedule | **Not set up** — production blocker (KNOWN_LIMITATIONS.md) |
| Off-site encrypted copy | **Not set up** |
| Restore drill | Never run |
| Host backups (cPanel) | Depends on the plan; check what the host keeps and for how long |
