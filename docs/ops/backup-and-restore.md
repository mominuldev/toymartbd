# Backup and restore

**Tasks:** P3-CI-03 (this document, the commands, the local rehearsal) · **Architecture:** `SYSTEM-ARCHITECTURE.md` §11 (Backups), scheduled tasks ("database backup (02:00)") · **Targets:** RPO 1 hour, RTO 4 hours.

> **Status, 2026-11-11.** The backup command and the restore rehearsal work and have been run locally against MySQL 9.7 (result below). **The P3-CI-03 acceptance criterion — a restore onto *staging* that produces a working application — is still open.** It needs a staging host and an off-host bucket, which the owner has not provisioned yet. The 1-hour RPO also needs binlog retention on the production MySQL host (see "Point-in-time recovery"). Neither can be done from this repository.

## What a backup is

`php artisan toymart:backup` writes two files to the disk named by `BACKUP_DISK`:

| File | Contents |
|---|---|
| `toymartbd-YYYYMMDD-HHMMSS.tar.gz.enc` (UTC timestamp) | `database.sql` (a full `mysqldump`) + `private/` and `public/` from `storage/app` (uploads, product media), gzipped tar, **AES-256-CBC encrypted** with a key derived from `BACKUP_ARCHIVE_PASSWORD` (PBKDF2-SHA256, 600 000 iterations, random salt) by `openssl enc` |
| `toymartbd-…manifest.json` | Plain JSON: SHA-256 of the archive, its size, exact row count per table, the applied migrations, the upload file count. **No secrets.** It is written last; a backup without a manifest is treated as incomplete and ignored. |

How it is taken:

- `mysqldump --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4` — a consistent InnoDB snapshot without locking tables, so it is safe during business hours. Bangla text survives because the dump is utf8mb4 end to end.
- The MySQL password goes into a temporary 0600 `--defaults-extra-file`, deleted at once. It never appears on a command line (`ps`), in the output or in a log.
- The archive password reaches `openssl` through its environment (`-pass env:`), never argv.
- Plaintext (the dump, the unencrypted tar) only exists in `storage/app/backup-temp/<name>/` (mode 0700), which is always deleted, on success or failure.
- The command refuses to run without a `BACKUP_ARCHIVE_PASSWORD` of at least 16 characters. It never writes an unencrypted backup.

**Never in a backup:** `.env`, `APP_KEY`, the archive password, the DB password. Escrow those separately (password manager + the host's secret store). Without the archive password a backup cannot be opened; without `APP_KEY` the restored `encrypted` columns (gateway and courier credentials, CLAUDE.md rule 20) cannot be read, and existing sessions are invalid.

### Why a focused command, not `spatie/laravel-backup`

Spatie's package is good at the *backup* half, but P3-CI-03 is judged on the *restore*, which it does not do. We would still need our own restore and verification code, and its encryption is ZIP AES (libzip-dependent; needs a ZIP tool that speaks AES to open by hand). The focused command is ~900 lines (six small classes) over standard tools, adds no dependency, and produces an archive an operator can open on a bare host with `openssl` and `tar` alone — which matters at 3 a.m. during an incident.

## Configuration

`config/backup.php`, `config/filesystems.php` (`backups`, `backups_s3`), `.env`:

| Variable | Default | Meaning |
|---|---|---|
| `BACKUP_DISK` | `backups` | Local disk at `storage/app/backups` (development). **Production: `backups_s3`.** |
| `BACKUP_ARCHIVE_PASSWORD` | — | Required. ≥ 16 chars; use `openssl rand -hex 32`. |
| `BACKUP_S3_KEY` / `_SECRET` / `_REGION` / `_BUCKET` / `_ENDPOINT` / `_PATH_STYLE` | — | Any S3-compatible store (AWS S3, Cloudflare R2, Backblaze B2, Wasabi). |
| `BACKUP_KEEP_ALL_DAYS` / `_DAILY_DAYS` / `_MONTHLY_MONTHS` | 7 / 30 / 12 | Retention, below. |
| `BACKUP_REHEARSAL_DATABASE` | `toymartbd_restore_check` | Scratch database for the rehearsal. |
| `BACKUP_DB_CONNECTION` | `mysql` | Connection to dump (MySQL only). |

**Off-host storage (production).** The `backups_s3` disk needs `league/flysystem-aws-s3-v3` (`composer require league/flysystem-aws-s3-v3`), not installed yet because no bucket exists. Use a dedicated bucket, outside the application host's provider if possible, with **versioning on** (so a compromised host cannot silently overwrite history) and a key scoped to that bucket only. Media is also meant to live in versioned object storage (§11); once it does, drop `public` from `backup.include` and rely on bucket versioning for it.

**Retention**, applied after each successful backup (`--no-prune` skips it): keep everything for 7 days; then the newest backup of each Dhaka calendar day up to 30 days; then the newest of each month up to 12 months; delete the rest. The newest backup is never deleted, however old.

## Schedule

`routes/console.php`:

```php
Schedule::command('toymart:backup')->dailyAt('02:00')->timezone('Asia/Dhaka')->withoutOverlapping(120)->onOneServer();
```

The host needs the standard Laravel cron entry (as the app user, not root):

```cron
* * * * * cd /var/www/toymartbd/current && php artisan schedule:run >> /dev/null 2>&1
```

A failed backup exits non-zero, is logged and goes to Sentry via `report()`. Add an uptime-monitor heartbeat (e.g. `->pingOnSuccess(env('BACKUP_HEARTBEAT_URL'))`) once the monitor exists, so a backup that *silently stops running* also alerts.

## Restore rehearsal

```bash
php artisan toymart:backup:restore-rehearsal                # latest backup, compare with the live source DB
php artisan toymart:backup:restore-rehearsal --against=manifest   # compare with the counts taken at backup time
php artisan toymart:backup:restore-rehearsal --backup=toymartbd-20261111-150310 --keep
```

It downloads the newest complete backup, checks the SHA-256 against the manifest (catches truncation or tampering), decrypts, unpacks, creates the scratch database (`utf8mb4` / `utf8mb4_0900_ai_ci`), loads the dump, and verifies:

1. the same set of tables exists;
2. exact `COUNT(*)` per table matches the reference (except volatile `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`);
3. `CHECKSUM TABLE` content matches, table by table (`--against=source` only) — proves the rows, Bangla text included, are identical, not just their number;
4. the applied migrations are identical, by name;
5. reference data is present: `roles`, `permissions`, `role_has_permissions`, `divisions`, `districts`, `upazilas` are non-empty;
6. the upload file count matches the manifest.

The scratch database is always dropped (unless `--keep`), the working directory always deleted. **Exit code 1 on any mismatch** — wire it into monitoring. It refuses a scratch name equal to the source database, or one outside `[A-Za-z0-9_]`.

Use `--against=source` when nothing writes to the source (local, staging restore, a quiet window). On a busy production database the live counts move after the dump; use `--against=manifest` there.

### Local run, 2026-11-11 (MySQL 9.7.2, macOS) — output abridged; the command prints one table row per check

```
$ php artisan toymart:backup
  Dumping database `toymartbd` ......................... DONE
  Archiving dump + uploads (2 files) ................... DONE
  Encrypting (AES-256-CBC, PBKDF2-SHA256) .............. DONE
  Writing to disk `backups` ............................ DONE
  INFO  Backup toymartbd-20261111-150310 written: 17.1 KiB, 21 tables, 856 rows, 0.9s.

$ php artisan toymart:backup:restore-rehearsal
  archive sha256 ............ pass       rows: roles 7 = 7 ............... pass
  tables present 21 = 21 .... pass       rows: permissions 64 = 64 ....... pass
  rows: divisions 8 = 8 ..... pass       rows: role_has_permissions 202 .. pass
  rows: districts 64 = 64 ... pass       rows: users 2 = 2 ............... pass
  rows: upazilas 495 = 495 .. pass       rows: migrations 12 = 12 ........ pass
  content checksums 16 tables, 16 identical ........................... pass
  migrations (names) 12 = 12 .......................................... pass
  seeded: roles/permissions/role_has_permissions/divisions/districts/upazilas > 0 ... pass
  upload files 2 = 2 .................................................. pass
  INFO  RESTORE REHEARSAL PASSED for toymartbd-20261111-150310 (32 checks).   exit 0
```

Negative checks, same day: a wrong archive password fails at "Decrypting" (`bad decrypt`), exit 1; a manifest edited to claim 8 roles fails `rows: roles 8 ≠ 7`, exit 1; in both cases the scratch database was dropped.

## Staging restore — the real acceptance test (blocked on infrastructure)

Monthly (§11, risk T10), and once before launch to close P3-CI-03:

1. On the **staging** host, with the production `BACKUP_ARCHIVE_PASSWORD` and read access to the production bucket, run `php artisan toymart:backup:restore-rehearsal --against=manifest`. It must pass.
2. Then a full restore into staging's own database: download the latest archive, decrypt and unpack by hand (below), `mysql staging_db < database.sql`, copy `private/` and `public/` into `storage/app/`, set staging's `APP_KEY` to production's **for the duration of the exercise only**, `php artisan migrate --force` (should be a no-op), `php artisan optimize`, `php artisan horizon:terminate`.
3. Smoke test: `GET /api/v1/health` is `ok`; an admin can log in; a known order, product and customer render; a product image loads; a gateway setting decrypts in the admin (proves `APP_KEY`).
4. Record date, backup name, duration (against the 4-hour RTO) and the result in this file. Restore staging's own `APP_KEY` and data afterwards.

**Blocked on:** a staging host, the production off-host bucket (+ `league/flysystem-aws-s3-v3`), and a production MySQL with binlogs. Owner infrastructure decision, not code.

## Manual restore (no Laravel available)

```bash
export BACKUP_ARCHIVE_PASSWORD='…'   # from the password manager
openssl enc -d -aes-256-cbc -pbkdf2 -md sha256 -iter 600000 \
  -in toymartbd-YYYYMMDD-HHMMSS.tar.gz.enc -out restore.tar.gz -pass env:BACKUP_ARCHIVE_PASSWORD
sha256sum toymartbd-YYYYMMDD-HHMMSS.tar.gz.enc   # compare with the manifest's sha256 first
mkdir restore && tar -xzf restore.tar.gz -C restore
mysql --default-character-set=utf8mb4 -e 'CREATE DATABASE toymartbd CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci'
mysql --default-character-set=utf8mb4 toymartbd < restore/database.sql
rsync -a restore/private/ restore/public/ /var/www/toymartbd/shared/storage/app/   # per directory
```

The dump includes routines and triggers. On a server with binary logging, loading triggers as a non-SUPER user needs `log_bin_trust_function_creators=1`; a `DEFINER` naming a user that does not exist on the target must be created or rewritten.

## Point-in-time recovery (RPO 1 hour)

A nightly dump alone gives a 24-hour RPO. The 1-hour target needs, on the production MySQL host: `binlog_expire_logs_seconds` ≥ 8 days, binlogs shipped off-host at least hourly (e.g. `mysqlbinlog --read-from-remote-server --raw --stop-never` to the backup bucket), and a recovery runbook (restore the nightly dump, then `mysqlbinlog --start-datetime=… | mysql`). This is host configuration; it is not built yet.
