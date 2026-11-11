<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly encrypted backup to the off-host disk (SYSTEM-ARCHITECTURE.md scheduled tasks,
// P3-CI-03, docs/ops/backup-and-restore.md). 02:00 Dhaka — the quietest hour, and after
// the 01:00 financial rollup. One server only, never two overlapping runs.
Schedule::command('toymart:backup')
    ->dailyAt('02:00')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping(120)
    ->onOneServer();
