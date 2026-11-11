<?php

declare(strict_types=1);

/*
| Backups and the restore rehearsal (P3-CI-03). See docs/ops/backup-and-restore.md.
|
| `php artisan toymart:backup` writes `<prefix>-<UTC timestamp>.tar.gz.enc` (AES-256-CBC,
| PBKDF2-SHA256, via `openssl enc`) plus a plain `.manifest.json` (checksum, row
| counts, migrations — no secrets) to the configured disk.
|
| Never in a backup: .env, APP_KEY, the archive password. Those are escrowed
| separately; without APP_KEY the restored `encrypted` columns cannot be read.
*/
return [

    'disk' => env('BACKUP_DISK', 'backups'),

    'archive_password' => env('BACKUP_ARCHIVE_PASSWORD'),

    'prefix' => 'toymartbd',

    // The database connection to dump (must be MySQL).
    'connection' => env('BACKUP_DB_CONNECTION', 'mysql'),

    // Uploads included in the archive, as paths relative to storage/app.
    'include' => [
        'private',
        'public',
    ],

    'pbkdf2_iterations' => 600000,

    'binaries' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL_BINARY', 'mysql'),
        'openssl' => env('BACKUP_OPENSSL_BINARY', 'openssl'),
        'tar' => env('BACKUP_TAR_BINARY', 'tar'),
    ],

    // Seconds a single dump / restore / encrypt step may take.
    'timeout' => (int) env('BACKUP_TIMEOUT', 3600),

    /*
    | Retention, applied after every successful backup. The newest backup is never
    | deleted. Keep every backup for `keep_all_for_days`, then the newest per Dhaka
    | day until `keep_daily_for_days`, then the newest per month until
    | `keep_monthly_for_months`; anything older is pruned.
    */
    'retention' => [
        'keep_all_for_days' => (int) env('BACKUP_KEEP_ALL_DAYS', 7),
        'keep_daily_for_days' => (int) env('BACKUP_KEEP_DAILY_DAYS', 30),
        'keep_monthly_for_months' => (int) env('BACKUP_KEEP_MONTHLY_MONTHS', 12),
    ],

    'rehearsal' => [
        // Scratch database the latest backup is restored into, then dropped.
        'database' => env('BACKUP_REHEARSAL_DATABASE', 'toymartbd_restore_check'),

        // Row counts here may legitimately differ between the dump and "now".
        'volatile_tables' => ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches'],

        // Reference data that must be present after a restore.
        'must_not_be_empty' => [
            'roles',
            'permissions',
            'role_has_permissions',
            'divisions',
            'districts',
            'upazilas',
        ],
    ],

];
