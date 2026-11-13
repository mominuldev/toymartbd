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

// Courier location mirror (P6, DATABASE-DESIGN §3.6): Pathao's city/zone/area tree, nightly
// at 04:00 Dhaka, queued on `courier`. Only once Pathao credentials exist. Maps nothing —
// mapping is a human decision (rule 32).
Schedule::command('toymart:couriers:sync-locations pathao --queue')
    ->dailyAt('04:00')
    ->timezone('Asia/Dhaka')
    ->when(static fn (): bool => App\Domain\Fulfilment\Drivers\Pathao\PathaoCredentials::resolve() !== null)
    ->onOneServer();

// Courier status reconciliation (P6): every in-flight shipment with no update for an hour is
// re-queried — webhooks get lost. Terminal shipments are never polled; work goes to `courier`.
Schedule::command('toymart:couriers:poll')
    ->everyFifteenMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();

// Scheduled content (P7) — ONE sweep: a homepage CMS block's start/end (content:home), a
// banner's start/end (content:banners) or a page's publish time (content:page:{slug} +
// page:{slug}) that just passed purges the storefront cache tags. Every minute.
Schedule::command('toymart:content:revalidate-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

// XML sitemaps (P7 SEO, docs/api/seo.md §5): rebuilt nightly at 03:00 Dhaka on `index`, then the
// storefront's cached copies are purged. The admin can also rebuild on demand.
Schedule::job(new App\Domain\Seo\Jobs\GenerateSitemaps)
    ->dailyAt('03:00')
    ->timezone('Asia/Dhaka')
    ->onOneServer();

// P7 marketing. Promotion start/end → storefront revalidation (ISR at the boundary), every
// minute. Abandoned-cart reminders every 10 minutes (quiet hours and consent in the Action).
Schedule::command('toymart:promotions:boundaries')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('toymart:carts:recover')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// P8 notification automation. Review requests hourly (delay after delivery, consent and the
// Dhaka quiet hours are in the Action); birthday messages at 10:00 Dhaka, once a year each.
Schedule::command('toymart:automations:review-requests')
    ->hourlyAt(5)
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('toymart:automations:birthdays')
    ->dailyAt('10:00')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping(30)
    ->onOneServer();

// P7 server-side tracking: retention of checkout IP / user agent / click ids
// (docs/api/tracking.md §3.3) — blanked after tracking.context_retention_days (90).
Schedule::command('toymart:tracking:prune-contexts')
    ->dailyAt('03:40')
    ->withoutOverlapping(30)
    ->onOneServer();

// P8 scheduled report emails: every 15 minutes, queue each schedule whose Dhaka send time has
// passed, once per (schedule, period) — the sent-log's UNIQUE key makes a re-run a no-op.
Schedule::command('toymart:reports:send-scheduled')
    ->everyFifteenMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();

// SYSTEM-ARCHITECTURE §7 scheduled tasks (P7 follow-up). Product search: the index-lag alert every
// five minutes (exits non-zero and logs at error on lag) and a zero-gap rebuild nightly at 04:30
// Dhaka, after the courier sync — both no-ops unless SCOUT_DRIVER=meilisearch.
Schedule::command('toymart:search:lag-check')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('toymart:search:reindex')
    ->dailyAt('04:30')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping(60)
    ->onOneServer();

// Stale cart cleanup every ten minutes: carts expired over a week ago that never became an order
// (abandoned-cart history kept). Carts hold no stock reservation — unpaid orders are released by
// toymart:payments:expire above.
Schedule::command('toymart:carts:prune')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
