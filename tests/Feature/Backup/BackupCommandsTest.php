<?php

declare(strict_types=1);

use App\Console\Commands\Backup\BackupCatalog;
use App\Console\Commands\Backup\RestoreRehearsalCommand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
| The end-to-end run (real mysqldump → encrypt → restore → verify) needs a MySQL
| server and is exercised by `php artisan toymart:backup:restore-rehearsal` itself —
| see docs/ops/backup-and-restore.md. These tests cover the guards and the policy.
*/

/** @return array{keep_all_for_days: int, keep_daily_for_days: int, keep_monthly_for_months: int} */
function backupRetentionPolicy(): array
{
    return ['keep_all_for_days' => 7, 'keep_daily_for_days' => 30, 'keep_monthly_for_months' => 12];
}

it('refuses to write a backup without an archive password', function (?string $password): void {
    config(['backup.archive_password' => $password]);

    $this->artisan('toymart:backup')
        ->expectsOutputToContain('Refusing to write an unencrypted backup')
        ->assertFailed();
})->with(['missing' => [null], 'too short' => ['short']]);

it('refuses to back up a non-MySQL connection and writes nothing', function (): void {
    Storage::fake('backups');
    config(['backup.archive_password' => str_repeat('x', 32), 'backup.connection' => 'sqlite']);

    $this->artisan('toymart:backup')
        ->expectsOutputToContain('mysql driver only')
        ->assertFailed();

    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

it('fails the rehearsal cleanly when there is no backup', function (): void {
    Storage::fake('backups');
    config(['backup.archive_password' => str_repeat('x', 32)]);

    $this->artisan('toymart:backup:restore-rehearsal')
        ->expectsOutputToContain('No complete backup found')
        ->assertFailed();
});

it('never lets the scratch database be the source database', function (string $scratch, string $source): void {
    RestoreRehearsalCommand::assertSafeScratchName($scratch, $source);
})->with([
    'same name' => ['toymartbd', 'toymartbd'],
    'same name, other case' => ['ToyMartBD', 'toymartbd'],
    'injection' => ['x`; DROP DATABASE toymartbd; --', 'toymartbd'],
])->throws(RuntimeException::class);

it('accepts the default scratch database name', function (): void {
    RestoreRehearsalCommand::assertSafeScratchName('toymartbd_restore_check', 'toymartbd');
})->throwsNoExceptions();

it('lists only complete backups, oldest first', function (): void {
    Storage::fake('backups');
    $disk = Storage::disk('backups');
    $disk->put('toymartbd-20261109-200000.tar.gz.enc', 'a');
    $disk->put('toymartbd-20261109-200000.manifest.json', '{}');
    $disk->put('toymartbd-20261110-200000.tar.gz.enc', 'b');
    $disk->put('toymartbd-20261110-200000.manifest.json', '{}');
    $disk->put('toymartbd-20261111-200000.tar.gz.enc', 'c'); // no manifest yet: incomplete
    $disk->put('notes.txt', 'x');

    $catalog = new BackupCatalog($disk, 'toymartbd');

    expect(array_keys($catalog->all()))->toBe(['toymartbd-20261109-200000', 'toymartbd-20261110-200000'])
        ->and($catalog->latest())->toBe('toymartbd-20261110-200000');
});

it('applies retention: all for 7 days, one per Dhaka day to 30, one per month to 12', function (): void {
    $now = CarbonImmutable::parse('2026-11-11 21:00:00', 'UTC');
    $backups = [];

    // Two backups a day for 400 days.
    for ($d = 0; $d < 400; $d++) {
        foreach (['20:00:00', '08:00:00'] as $time) {
            $at = $now->subDays($d)->setTimeFromTimeString($time);
            $backups['toymartbd-'.$at->format('Ymd-His')] = $at;
        }
    }

    $kept = array_diff_key($backups, array_flip(BackupCatalog::toPrune($backups, $now, backupRetentionPolicy())));
    $keptBetween = fn (int $fromDaysAgo, int $toDaysAgo): int => count(array_filter(
        $kept,
        fn (CarbonImmutable $at): bool => $at->lessThan($now->subDays($fromDaysAgo)) && $at->greaterThanOrEqualTo($now->subDays($toDaysAgo)),
    ));

    expect($keptBetween(0, 7))->toBe(14)            // every backup of the last week
        ->and($keptBetween(8, 30))->toBe(22)        // one per day
        ->and($keptBetween(40, 330))->toBeLessThanOrEqual(11) // one per month
        ->and($keptBetween(380, 400))->toBe(0)      // older than 12 months: pruned
        ->and($kept)->toHaveKey('toymartbd-20261111-200000'); // the newest
});

it('always keeps the newest backup, however old', function (): void {
    $old = CarbonImmutable::parse('2024-01-01 20:00:00', 'UTC');

    expect(BackupCatalog::toPrune(['toymartbd-20240101-200000' => $old], CarbonImmutable::now(), backupRetentionPolicy()))->toBe([]);
});
