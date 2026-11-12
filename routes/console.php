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

// Nightly stock reconciliation (DATABASE-DESIGN §7, P5-INV): ledger vs levels, alert and
// mail on drift, never auto-correct. 03:30 Dhaka — after the backup, before the day starts.
Schedule::command('toymart:inventory:reconcile')
    ->dailyAt('03:30')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping(60)
    ->onOneServer();

// Daily low-stock digest to managers (SYSTEM-ARCHITECTURE §6: 09:00 Asia/Dhaka), reporting
// the previous Dhaka day's alerts and everything still open.
Schedule::command('toymart:inventory:digest')
    ->dailyAt((string) config('inventory.alerts.digest_time', '09:00'))
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping(30)
    ->onOneServer();

// Finance (P5, DATABASE-DESIGN §3.7, §7). Recurring expenses on the 1st at 00:30 Dhaka;
// the daily rollup at 01:00 Dhaka (yesterday + today); the §7.2 invariant at 01:30, which
// exits non-zero on drift and never corrects it.
Schedule::command('toymart:finance:recurring-expenses')
    ->monthlyOn(1, '00:30')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('toymart:finance:rollup')
    ->dailyAt('01:00')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('toymart:finance:verify')
    ->dailyAt('01:30')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping(30)
    ->onOneServer();

// Online payments (P4-PAY-04): unpaid online orders past their window are re-queried, then
// expired — stock released, order cancelled. Every five minutes.
Schedule::command('toymart:payments:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
