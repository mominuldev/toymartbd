<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use App\Models\SystemHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * php artisan toymart:backup:restore-rehearsal — proves the latest backup restores.
 *
 * Downloads the newest complete backup, checks its SHA-256 against the manifest,
 * decrypts and unpacks it, loads the dump into a scratch database
 * (`toymartbd_restore_check`), then compares it table by table against a reference —
 * the live source database (default) or the manifest's own counts
 * (`--against=manifest`, for a busy production database whose counts have moved
 * since the dump). The scratch database is always dropped. Exit 1 on any mismatch.
 *
 * This is the local half of P3-CI-03; the acceptance criterion — a restore onto
 * staging that produces a working application — needs a staging host.
 */
final class RestoreRehearsalCommand extends Command
{
    protected $signature = 'toymart:backup:restore-rehearsal
        {--backup= : A specific backup name instead of the latest}
        {--against=source : Reference to compare with: source | manifest}
        {--keep : Leave the scratch database in place for inspection}';

    protected $description = 'Restore the latest backup into a scratch database, verify it, then drop it';

    private const string CONNECTION = 'backup_rehearsal';

    /** @var list<array{string, string, string, string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $password = config('backup.archive_password');
        $against = (string) $this->option('against');

        if (! is_string($password) || $password === '') {
            $this->components->error('BACKUP_ARCHIVE_PASSWORD is not set; cannot decrypt.');

            return self::FAILURE;
        }

        if (! in_array($against, ['source', 'manifest'], true)) {
            $this->components->error('--against must be "source" or "manifest".');

            return self::FAILURE;
        }

        $connectionName = (string) config('backup.connection');
        /** @var array<string, mixed> $connectionConfig */
        $connectionConfig = config('database.connections.'.$connectionName, []);
        $scratch = (string) config('backup.rehearsal.database');

        try {
            $mysql = new MysqlCli(
                $connectionConfig,
                (string) config('backup.binaries.mysqldump'),
                (string) config('backup.binaries.mysql'),
                (int) config('backup.timeout'),
            );
            self::assertSafeScratchName($scratch, $mysql->database());
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $disk = Storage::disk((string) config('backup.disk'));
        $catalog = new BackupCatalog($disk, (string) config('backup.prefix'));
        $option = $this->option('backup');
        $name = is_string($option) && $option !== '' ? $option : $catalog->latest();

        if ($name === null || ! $disk->exists(BackupCatalog::archivePath($name)) || ! $disk->exists(BackupCatalog::manifestPath($name))) {
            $this->components->error('No complete backup found on disk `'.config('backup.disk').'`.');

            return self::FAILURE;
        }

        /** @var array{sha256: string, database: array{tables: array<string, int>, migrations: list<string>}, uploads: array{entries: list<string>, file_count: int}} $manifest */
        $manifest = json_decode((string) $disk->get(BackupCatalog::manifestPath($name)), true, flags: JSON_THROW_ON_ERROR);
        $tools = new ArchiveTools(
            (string) config('backup.binaries.tar'),
            (string) config('backup.binaries.openssl'),
            (int) config('backup.pbkdf2_iterations'),
            (int) config('backup.timeout'),
        );
        $work = storage_path('app/backup-temp/rehearsal-'.$name);
        $restoredDatabase = false;

        $this->components->info('Rehearsing restore of '.$name.' into `'.$scratch.'` (reference: '.$against.').');

        try {
            File::ensureDirectoryExists($work.'/extract', 0700);
            chmod($work, 0700);
            $encrypted = $work.'/'.BackupCatalog::archivePath($name);

            $this->components->task('Downloading archive', function () use ($disk, $name, $encrypted): void {
                $in = $disk->readStream(BackupCatalog::archivePath($name));
                $out = fopen($encrypted, 'wb');

                if ($in === null || $out === false) {
                    throw new RuntimeException('Cannot copy the archive to the working directory.');
                }

                stream_copy_to_stream($in, $out);
                fclose($out);
                fclose($in);
            });

            $sha = hash_file('sha256', $encrypted);
            $this->check('archive sha256', substr($manifest['sha256'], 0, 16).'…', substr((string) $sha, 0, 16).'…', $sha === $manifest['sha256']);

            if ($this->failed) {
                throw new RuntimeException('Archive checksum does not match its manifest — corrupt or tampered.');
            }

            $plain = $work.'/archive.tar.gz';
            $this->components->task('Decrypting', fn () => $tools->decrypt($encrypted, $plain, $password));
            $this->components->task('Unpacking', fn () => $tools->untar($plain, $work.'/extract'));
            File::delete([$encrypted, $plain]);

            $this->components->task('Creating scratch database', function () use ($mysql, $scratch): void {
                $mysql->statement('DROP DATABASE IF EXISTS `'.$scratch.'`');
                $mysql->statement(sprintf('CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s', $scratch, $mysql->charset(), $mysql->collation()));
            });
            $restoredDatabase = true;

            $this->components->task('Loading dump', fn () => $mysql->import($work.'/extract/database.sql', $scratch));

            config(['database.connections.'.self::CONNECTION => [...$connectionConfig, 'database' => $scratch]]);
            DB::purge(self::CONNECTION);
            /** @var Connection $restoredConnection */
            $restoredConnection = DB::connection(self::CONNECTION);
            $restored = new DatabaseInventory($restoredConnection);

            if ($against === 'source') {
                /** @var Connection $sourceConnection */
                $sourceConnection = DB::connection($connectionName);
                $source = new DatabaseInventory($sourceConnection);
                $referenceCounts = $source->counts();
                $referenceMigrations = $source->migrations();
                $referenceChecksums = $source->checksums(array_keys($referenceCounts));
            } else {
                $referenceCounts = $manifest['database']['tables'];
                $referenceMigrations = $manifest['database']['migrations'];
                // Content checksums only against the live source: the manifest counts are
                // taken just before the dump, so content can legitimately have moved.
                $referenceChecksums = [];
            }

            $this->verify($referenceCounts, $referenceChecksums, $referenceMigrations, $restored, $manifest['uploads'], $work.'/extract');
        } catch (Throwable $e) {
            $this->failed = true;
            $this->components->error('Rehearsal aborted: '.$e->getMessage());
        } finally {
            DB::purge(self::CONNECTION);

            if ($restoredDatabase && ! $this->option('keep')) {
                try {
                    $mysql->statement('DROP DATABASE IF EXISTS `'.$scratch.'`');
                    $this->components->info('Scratch database `'.$scratch.'` dropped.');
                } catch (Throwable $e) {
                    $this->failed = true;
                    $this->components->error('Could not drop `'.$scratch.'`: '.$e->getMessage());
                }
            }

            File::deleteDirectory($work);
        }

        if ($this->rows !== []) {
            $this->table(['Check', 'Expected', 'Restored', 'Result'], $this->rows);
        }

        // The evidence `toymart:launch:check` and the monthly drill read (docs/ops/LAUNCH-CHECKLIST.md).
        try {
            (new RehearsalLog($disk))->record($name, ! $this->failed, $against, count($this->rows));
        } catch (Throwable $e) {
            $this->failed = true;
            $this->components->error('Could not record the rehearsal result: '.$e->getMessage());
        }

        if ($this->failed) {
            SystemHeartbeat::failed(SystemHeartbeat::RESTORE_REHEARSAL, 'Rehearsal of '.$name.' failed.');
            $this->components->error('RESTORE REHEARSAL FAILED for '.$name.'.');

            return self::FAILURE;
        }

        // For the restore_rehearsal_stale alert (P10 monitoring); RehearsalLog above is the evidence.
        SystemHeartbeat::beat(SystemHeartbeat::RESTORE_REHEARSAL, ['backup' => $name, 'checks' => count($this->rows)]);
        $this->components->info('RESTORE REHEARSAL PASSED for '.$name.' ('.count($this->rows).' checks).');

        return self::SUCCESS;
    }

    /** The scratch name is interpolated into DROP DATABASE: it must never be the real one. */
    public static function assertSafeScratchName(string $scratch, string $source): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $scratch) !== 1) {
            throw new RuntimeException('The rehearsal database name must match [A-Za-z0-9_]{1,64}.');
        }

        if (strcasecmp($scratch, $source) === 0) {
            throw new RuntimeException('The rehearsal database must not be the source database.');
        }
    }

    /**
     * @param  array<string, int>  $referenceCounts
     * @param  array<string, string|null>  $referenceChecksums  empty = not compared
     * @param  list<string>  $referenceMigrations
     * @param  array{entries: list<string>, file_count: int}  $uploads
     */
    private function verify(array $referenceCounts, array $referenceChecksums, array $referenceMigrations, DatabaseInventory $restored, array $uploads, string $extract): void
    {
        $restoredCounts = $restored->counts();
        /** @var list<string> $volatile */
        $volatile = config('backup.rehearsal.volatile_tables', []);

        $missing = array_diff(array_keys($referenceCounts), array_keys($restoredCounts));
        $extra = array_diff(array_keys($restoredCounts), array_keys($referenceCounts));
        $this->check('tables present', count($referenceCounts).' tables', count($restoredCounts).' tables', $missing === [] && $extra === [],
            $missing === [] && $extra === [] ? '' : ' missing: '.implode(',', $missing).' extra: '.implode(',', $extra));

        foreach ($referenceCounts as $table => $expected) {
            if (! array_key_exists($table, $restoredCounts)) {
                continue;
            }

            $actual = $restoredCounts[$table];

            if (in_array($table, $volatile, true)) {
                $this->rows[] = ['rows: '.$table, (string) $expected, (string) $actual, 'skip (volatile)'];

                continue;
            }

            $this->check('rows: '.$table, (string) $expected, (string) $actual, $expected === $actual);
        }

        if ($referenceChecksums !== []) {
            $comparable = array_values(array_diff(array_keys($referenceChecksums), $volatile, $missing));
            $restoredChecksums = $restored->checksums($comparable);
            $differs = array_values(array_filter($comparable, fn (string $t): bool => $referenceChecksums[$t] !== $restoredChecksums[$t]));
            $this->check('content checksums', count($comparable).' tables', (count($comparable) - count($differs)).' identical', $differs === [],
                $differs === [] ? '' : ' differ: '.implode(',', $differs));
        }

        $restoredMigrations = $restored->migrations();
        $this->check('migrations (names)', count($referenceMigrations).' applied', count($restoredMigrations).' applied', $restoredMigrations === $referenceMigrations);

        /** @var list<string> $mustNotBeEmpty */
        $mustNotBeEmpty = config('backup.rehearsal.must_not_be_empty', []);

        foreach ($mustNotBeEmpty as $table) {
            $count = $restoredCounts[$table] ?? 0;
            $this->check('seeded: '.$table, '> 0', (string) $count, $count > 0);
        }

        $restoredFiles = 0;
        foreach ($uploads['entries'] as $entry) {
            $restoredFiles += BackupCommand::countFiles($extract.'/'.$entry);
        }
        $this->check('upload files', (string) $uploads['file_count'], (string) $restoredFiles, $restoredFiles === $uploads['file_count']);
    }

    private function check(string $label, string $expected, string $actual, bool $ok, string $note = ''): void
    {
        $this->rows[] = [$label, $expected, $actual, ($ok ? 'pass' : 'FAIL').$note];
        $this->failed = $this->failed || ! $ok;
    }
}
