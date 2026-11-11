<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * php artisan toymart:backup — the nightly encrypted backup (P3-CI-03).
 *
 * 1. Records exact row counts + migrations (the manifest the rehearsal checks against).
 * 2. mysqldump --single-transaction (consistent, non-locking) in utf8mb4.
 * 3. tar.gz of the dump + uploads under storage/app/{private,public}.
 * 4. AES-256 via `openssl enc` with BACKUP_ARCHIVE_PASSWORD.
 * 5. Uploads archive + manifest to BACKUP_DISK, then applies retention.
 *
 * Plaintext only ever exists in a 0700 working directory that is always removed.
 * Never backed up: .env, APP_KEY, the archive password.
 */
final class BackupCommand extends Command
{
    protected $signature = 'toymart:backup
        {--no-prune : Skip the retention policy after this backup}';

    protected $description = 'Dump MySQL + uploads into an encrypted archive on the backup disk';

    private const int MIN_PASSWORD_LENGTH = 16;

    public function handle(): int
    {
        $password = config('backup.archive_password');

        if (! is_string($password) || strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->components->error('BACKUP_ARCHIVE_PASSWORD is not set (or shorter than '.self::MIN_PASSWORD_LENGTH.' characters). Refusing to write an unencrypted backup.');

            return self::FAILURE;
        }

        $startedAt = CarbonImmutable::now('UTC');
        $diskName = (string) config('backup.disk');
        $disk = Storage::disk($diskName);
        $catalog = new BackupCatalog($disk, (string) config('backup.prefix'));
        $name = $catalog->nameFor($startedAt);
        $work = storage_path('app/backup-temp/'.$name);

        try {
            $connectionName = (string) config('backup.connection');
            /** @var array<string, mixed> $connectionConfig */
            $connectionConfig = config('database.connections.'.$connectionName, []);
            $mysql = new MysqlCli(
                $connectionConfig,
                (string) config('backup.binaries.mysqldump'),
                (string) config('backup.binaries.mysql'),
                (int) config('backup.timeout'),
            );
            $tools = new ArchiveTools(
                (string) config('backup.binaries.tar'),
                (string) config('backup.binaries.openssl'),
                (int) config('backup.pbkdf2_iterations'),
                (int) config('backup.timeout'),
            );

            File::ensureDirectoryExists($work, 0700);
            chmod($work, 0700);

            /** @var Connection $connection */
            $connection = DB::connection($connectionName);
            $inventory = new DatabaseInventory($connection);
            $counts = $inventory->counts();
            $migrations = $inventory->migrations();

            $this->components->task('Dumping database `'.$mysql->database().'`', fn () => $mysql->dump($work.'/database.sql'));

            $uploads = $this->uploadEntries();
            $plain = $work.'/'.$name.'.tar.gz';
            $this->components->task('Archiving dump + uploads ('.$uploads['file_count'].' files)', fn () => $tools->tar($plain, [
                $work => ['database.sql'],
                storage_path('app') => $uploads['entries'],
            ]));

            $encrypted = $work.'/'.BackupCatalog::archivePath($name);
            $this->components->task('Encrypting (AES-256-CBC, PBKDF2-SHA256)', fn () => $tools->encrypt($plain, $encrypted, $password));
            File::delete([$plain, $work.'/database.sql']);

            $manifest = [
                'name' => $name,
                'format' => 1,
                'created_at' => $startedAt->toIso8601String(),
                'archive' => BackupCatalog::archivePath($name),
                'sha256' => hash_file('sha256', $encrypted),
                'bytes' => filesize($encrypted),
                'encryption' => ['cipher' => 'aes-256-cbc', 'kdf' => 'pbkdf2-sha256', 'iterations' => (int) config('backup.pbkdf2_iterations'), 'tool' => 'openssl enc'],
                'database' => ['driver' => 'mysql', 'name' => $mysql->database(), 'charset' => 'utf8mb4', 'tables' => $counts, 'migrations' => $migrations],
                'uploads' => ['entries' => $uploads['entries'], 'file_count' => $uploads['file_count']],
                'app_env' => app()->environment(),
            ];

            $this->components->task('Writing to disk `'.$diskName.'`', function () use ($disk, $encrypted, $name, $manifest): void {
                $stream = fopen($encrypted, 'rb');

                if ($stream === false) {
                    throw new RuntimeException('Cannot read the encrypted archive.');
                }

                try {
                    $disk->writeStream(BackupCatalog::archivePath($name), $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                // The manifest goes last: a backup without one is incomplete and ignored.
                $disk->put(BackupCatalog::manifestPath($name), (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            });
        } catch (Throwable $e) {
            report($e); // log + Sentry
            $this->components->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }

        $this->components->info(sprintf(
            'Backup %s written: %s, %d tables, %d rows, %.1fs.',
            $name,
            $this->humanBytes((int) $manifest['bytes']),
            count($counts),
            array_sum($counts),
            CarbonImmutable::now('UTC')->diffInMilliseconds($startedAt, true) / 1000,
        ));
        Log::info('Backup written.', ['backup' => $name, 'disk' => $diskName, 'bytes' => $manifest['bytes']]);

        if (! $this->option('no-prune')) {
            /** @var array{keep_all_for_days: int, keep_daily_for_days: int, keep_monthly_for_months: int} $policy */
            $policy = config('backup.retention');
            $pruned = $catalog->prune(CarbonImmutable::now('UTC'), $policy);
            $this->components->info($pruned === [] ? 'Retention: nothing to prune.' : 'Retention pruned: '.implode(', ', $pruned));
        }

        return self::SUCCESS;
    }

    /** @return array{entries: list<string>, file_count: int} */
    private function uploadEntries(): array
    {
        $entries = [];
        $count = 0;

        /** @var list<string> $include */
        $include = config('backup.include', []);

        foreach ($include as $entry) {
            $path = storage_path('app/'.$entry);

            if (! is_dir($path)) {
                continue;
            }

            $entries[] = $entry;
            $count += self::countFiles($path);
        }

        return ['entries' => $entries, 'file_count' => $count];
    }

    public static function countFiles(string $directory): int
    {
        return is_dir($directory) ? iterator_count(Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($directory)) : 0;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? sprintf('%.1f MiB', $bytes / 1048576) : sprintf('%.1f KiB', $bytes / 1024);
    }
}
