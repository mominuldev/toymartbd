<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Monitoring\Alerts\DiskSpaceCheck;
use App\Domain\Monitoring\Alerts\NoOrdersCheck;
use App\Domain\Monitoring\Mail\MonitoringAlertMail;
use App\Domain\Monitoring\Models\MonitoringAlert;
use App\Domain\Monitoring\Services\AlertRegistry;
use App\Domain\Monitoring\Services\AlertRunner;
use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\SystemHeartbeat;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * P10 business alerts (SYSTEM-ARCHITECTURE §11): each check fires on its condition, the run is
 * idempotent and de-duplicated, the owner channel is mail (+ SMS for critical), thresholds come
 * from settings, and `toymart:alerts:test` fires a [TEST] notice without touching state.
 */

final class AlertSmsSpy implements SmsGateway
{
    /** @var list<array{phone: string, body: string, template: string}> */
    public array $sent = [];

    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        $this->sent[] = ['phone' => $e164Phone, 'body' => $body, 'template' => $template];

        return SmsResult::accepted('spy-'.count($this->sent), 1, Money::zero());
    }
}

beforeEach(function (): void {
    Mail::fake();
    $this->sms = new AlertSmsSpy;
    app()->instance(SmsGateway::class, $this->sms);
    config([
        'monitoring.alerts.enabled' => true,
        'monitoring.alerts.channels.mail' => ['owner@example.test'],
        'monitoring.alerts.channels.sms' => ['01712345678'],
        'monitoring.alerts.channels.sms_min_severity' => 'critical',
    ]);
    DiskSpaceCheck::$probe = static fn (): array => ['free' => 100.0 * 1024 ** 3, 'total' => 200.0 * 1024 ** 3];
    // Healthy baseline for the heartbeat-driven checks.
    SystemHeartbeat::beat(SystemHeartbeat::BACKUP);
    SystemHeartbeat::beat(SystemHeartbeat::RESTORE_REHEARSAL);
});

afterEach(function (): void {
    DiskSpaceCheck::$probe = null;
});

/** @return array<string, array{result: mixed, decision: mixed, error: mixed}> */
function runAlerts(?array $only = null, ?CarbonImmutable $now = null): array
{
    return app(AlertRunner::class)->run($now ?? CarbonImmutable::now(), $only);
}

function inFlightShipment(string $provider, CarbonImmutable $updatedAt): Shipment
{
    $order = Order::factory()->create(['created_at' => $updatedAt->subHour()]);
    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider_id' => CourierProvider::query()->where('code', $provider)->value('id'),
        'number' => 'SHP-26-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'status' => ShipmentStatus::Booked,
        'consignment_id' => (string) random_int(100000, 999999),
        'weight_grams' => 900,
        'item_count' => 1,
        'cod_amount' => '1000.00',
        'declared_value' => '1000.00',
        'booked_at' => $updatedAt,
    ]);
    DB::table('shipments')->where('id', $shipment->id)->update(['updated_at' => $updatedAt]);

    return $shipment;
}

function smsLog(string $status, CarbonImmutable $at): void
{
    DB::table('notification_logs')->insert([
        'channel' => 'sms', 'template' => 'order.placed', 'category' => 'transactional', 'locale' => 'en',
        'status' => $status, 'cost' => '0.00', 'attempts' => 1,
        'sent_at' => $status === 'sent' ? $at : null, 'failed_at' => $status === 'sent' ? null : $at,
        'created_at' => $at, 'updated_at' => $at,
    ]);
}

it('registers every alert the brief lists, each with a test trigger', function (): void {
    expect(array_keys(app(AlertRegistry::class)->all()))->toEqualCanonicalizing([
        'no_orders', 'courier_booking_failures', 'failed_jobs', 'courier_poll_silence', 'courier_webhook_silence',
        'sms_failures', 'backup_stale', 'restore_rehearsal_stale', 'horizon_down', 'queue_depth', 'disk_space',
    ]);
});

it('fires no_orders after N quiet business hours, and stays silent pre-launch and at night', function (): void {
    // 15:00 Dhaka = 09:00 UTC.
    $now = CarbonImmutable::parse('2026-12-10 09:00:00', 'UTC');

    expect(runAlerts(['no_orders'], $now)['no_orders']['result']->applicable)->toBeFalse(); // pre-launch: no orders ever

    Order::factory()->create(['created_at' => $now->subDays(2)]);
    $outcome = runAlerts(['no_orders'], $now)['no_orders'];
    expect($outcome['result']->firing)->toBeTrue()
        ->and($outcome['decision']->value)->toBe('fire')
        ->and($outcome['result']->summary())->toContain('3 business hours');

    // 02:00 Dhaka: outside business hours, never fires.
    expect(runAlerts(['no_orders'], CarbonImmutable::parse('2026-12-09 20:00:00', 'UTC'))['no_orders']['result']->applicable)->toBeFalse();

    Order::factory()->create(['created_at' => $now->subMinutes(30)]);
    expect(runAlerts(['no_orders'], $now)['no_orders']['decision']->value)->toBe('resolve');
});

it('counts business hours back across the night', function (): void {
    $dhaka = static fn (string $t): CarbonImmutable => CarbonImmutable::parse($t, 'Asia/Dhaka');

    expect(NoOrdersCheck::windowStart($dhaka('2026-12-10 15:00'), 3, [9, 0], [23, 0])->format('Y-m-d H:i'))->toBe('2026-12-10 12:00')
        ->and(NoOrdersCheck::windowStart($dhaka('2026-12-10 10:00'), 3, [9, 0], [23, 0])->format('Y-m-d H:i'))->toBe('2026-12-09 21:00')
        ->and(NoOrdersCheck::windowStart($dhaka('2026-12-10 09:30'), 20, [9, 0], [23, 0])->format('Y-m-d H:i'))->toBe('2026-12-08 17:30'); // 0.5 h + 14 h + 5.5 h
});

it('is idempotent and de-duplicated: one notice, a reminder only after renotify_minutes, one resolve', function (): void {
    SystemHeartbeat::query()->whereKey(SystemHeartbeat::BACKUP)->delete();
    $now = CarbonImmutable::now();

    expect(runAlerts(['backup_stale'], $now)['backup_stale']['decision']->value)->toBe('fire')
        ->and(runAlerts(['backup_stale'], $now->addMinutes(5))['backup_stale']['decision']->value)->toBe('none')
        ->and(runAlerts(['backup_stale'], $now->addMinutes(10))['backup_stale']['decision']->value)->toBe('none');
    Mail::assertSentCount(1);

    expect(runAlerts(['backup_stale'], $now->addMinutes(241))['backup_stale']['decision']->value)->toBe('remind');
    Mail::assertSentCount(2);

    SystemHeartbeat::beat(SystemHeartbeat::BACKUP);
    expect(runAlerts(['backup_stale'], $now->addMinutes(242))['backup_stale']['decision']->value)->toBe('resolve')
        ->and(runAlerts(['backup_stale'], $now->addMinutes(247))['backup_stale']['decision']->value)->toBe('none');
    Mail::assertSentCount(3);

    $alert = MonitoringAlert::query()->findOrFail('backup_stale');
    expect($alert->state->value)->toBe('ok')->and($alert->notify_count)->toBe(3)->and($alert->resolved_at)->not->toBeNull();
});

it('mails the owner channel and texts critical alerts, with no PII in the SMS', function (): void {
    SystemHeartbeat::query()->whereKey(SystemHeartbeat::BACKUP)->delete();

    runAlerts(['backup_stale']);

    Mail::assertSent(MonitoringAlertMail::class, fn (MonitoringAlertMail $m): bool => $m->hasTo('owner@example.test')
        && $m->alertName === 'backup_stale'
        && str_contains($m->subjectLine, 'Critical alert: Backup overdue'));
    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0]['phone'])->toBe('+8801712345678')
        ->and($this->sms->sent[0]['body'])->toContain('Toy Mart BD FIRING backup_stale');
});

it('does not text a warning-level alert unless the owner lowers the SMS threshold', function (): void {
    SystemHeartbeat::query()->whereKey(SystemHeartbeat::RESTORE_REHEARSAL)->delete();
    config(['backup.disk' => 'local']);

    runAlerts(['restore_rehearsal_stale']);
    Mail::assertSentCount(1);
    expect($this->sms->sent)->toBe([]);
});

it('falls back to every active super admin when no alert e-mail is configured', function (): void {
    config(['monitoring.alerts.channels.mail' => []]);
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
    $admin = App\Models\User::factory()->create(['email' => 'admin@example.test', 'is_active' => true]);
    $admin->assignRole(App\Models\Enums\Role::SuperAdmin->value);
    SystemHeartbeat::query()->whereKey(SystemHeartbeat::BACKUP)->delete();

    runAlerts(['backup_stale']);

    Mail::assertSent(MonitoringAlertMail::class, fn (MonitoringAlertMail $m): bool => $m->hasTo('admin@example.test'));
});

it('reads thresholds from settings before config', function (): void {
    foreach (range(1, 3) as $i) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Illuminate\Support\Str::uuid(), 'connection' => 'redis', 'queue' => 'notify', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subMinutes($i)]);
    }
    expect(runAlerts(['failed_jobs'])['failed_jobs']['result']->firing)->toBeFalse();

    Setting::put('monitoring.alerts.failed_jobs.threshold', 2, SettingGroup::System);
    $result = runAlerts(['failed_jobs'])['failed_jobs']['result'];
    expect($result->firing)->toBeTrue()->and($result->summary())->toContain('3 background jobs failed');
});

it('fires on a courier booking failure rate above the threshold', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    foreach (range(1, 3) as $i) {
        inFlightShipment('steadfast', CarbonImmutable::now())->forceFill(['status' => ShipmentStatus::Failed, 'last_attempt_at' => now()->subMinutes(2)])->save();
    }
    inFlightShipment('steadfast', CarbonImmutable::now())->forceFill(['last_attempt_at' => now()->subMinutes(2)])->save();

    $result = runAlerts(['courier_booking_failures'])['courier_booking_failures']['result'];
    expect($result->firing)->toBeTrue()->and($result->summary())->toContain('3 of 4 courier bookings failed');
});

it('fires on courier poll silence and webhook silence only while a courier holds stale shipments', function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    CourierProvider::query()->update(['is_active' => true]);

    expect(runAlerts(['courier_poll_silence'])['courier_poll_silence']['result']->firing)->toBeFalse();

    inFlightShipment('steadfast', CarbonImmutable::now()->subHours(7));
    $poll = runAlerts(['courier_poll_silence', 'courier_webhook_silence']);
    expect($poll['courier_poll_silence']['result']->firing)->toBeTrue()
        ->and($poll['courier_poll_silence']['result']->summary())->toContain('steadfast')
        ->and($poll['courier_webhook_silence']['result']->firing)->toBeTrue();

    SystemHeartbeat::beat(SystemHeartbeat::COURIER_PREFIX.'steadfast');
    CourierWebhookReceipt::query()->create(['provider' => 'steadfast', 'external_event_id' => 'evt-1', 'signature_valid' => true, 'payload' => [], 'source' => 'webhook']);
    $after = runAlerts(['courier_poll_silence', 'courier_webhook_silence']);
    expect($after['courier_poll_silence']['result']->firing)->toBeFalse()
        ->and($after['courier_webhook_silence']['result']->firing)->toBeFalse();
});

it('fires on an SMS failure spike, not on a single failure', function (): void {
    $now = CarbonImmutable::now();
    smsLog('failed', $now->subMinutes(1));
    smsLog('sent', $now->subMinutes(1));
    expect(runAlerts(['sms_failures'])['sms_failures']['result']->firing)->toBeFalse();

    foreach (range(1, 5) as $i) {
        smsLog($i % 2 === 0 ? 'dead_lettered' : 'failed', $now->subMinutes($i));
    }
    $result = runAlerts(['sms_failures'])['sms_failures']['result'];
    expect($result->firing)->toBeTrue()->and($result->summary())->toContain('6 of 7 SMS sends failed');
});

it('fires on backup and restore-rehearsal age', function (): void {
    $this->travel(27)->hours();
    expect(runAlerts(['backup_stale'])['backup_stale']['result']->summary())->toContain('27 hours old');

    $this->travel(35)->days();
    config(['backup.disk' => 'local']);
    expect(runAlerts(['restore_rehearsal_stale'])['restore_rehearsal_stale']['result']->firing)->toBeTrue();
});

it('fires on queue depth, skips Horizon off Redis, and fires on low disk', function (): void {
    config(['queue.default' => 'database']);
    Setting::put('monitoring.alerts.queue_depth.threshold', 1, SettingGroup::System);
    foreach (range(1, 2) as $i) {
        DB::table('jobs')->insert(['queue' => 'courier', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
    }
    DiskSpaceCheck::$probe = static fn (): array => ['free' => 1.0 * 1024 ** 3, 'total' => 100.0 * 1024 ** 3];

    $out = runAlerts(['queue_depth', 'horizon_down', 'disk_space']);

    expect($out['queue_depth']['result']->summary())->toContain('courier=2')
        ->and($out['horizon_down']['result']->applicable)->toBeFalse()
        ->and($out['disk_space']['result']->firing)->toBeTrue();
});

it('does nothing on schedule while alerts are disabled', function (): void {
    config(['monitoring.alerts.enabled' => false]);
    SystemHeartbeat::query()->whereKey(SystemHeartbeat::BACKUP)->delete();

    $this->artisan('toymart:alerts:run')->assertSuccessful();
    Mail::assertNothingSent();
    expect(MonitoringAlert::query()->count())->toBe(0);
});

it('exits non-zero from the scheduled run while an alert is firing', function (): void {
    SystemHeartbeat::query()->whereKey(SystemHeartbeat::BACKUP)->delete();

    $this->artisan('toymart:alerts:run', ['--only' => ['backup_stale']])->assertFailed();
});

it('fires a [TEST] alert through the real channel without touching alert state', function (): void {
    $this->artisan('toymart:alerts:test', ['name' => 'no_orders'])->assertSuccessful();

    Mail::assertSent(MonitoringAlertMail::class, fn (MonitoringAlertMail $m): bool => $m->isTest && str_starts_with($m->subjectLine, '[TEST]'));
    expect($this->sms->sent[0]['body'])->toStartWith('[TEST] Toy Mart BD FIRING no_orders');
    $alert = MonitoringAlert::query()->findOrFail('no_orders');
    expect($alert->state->value)->toBe('ok')->and($alert->last_test_at)->not->toBeNull()->and($alert->notify_count)->toBe(0);
});

it('test-fires every alert with "all", lists names, and rejects an unknown one', function (): void {
    $this->artisan('toymart:alerts:test', ['name' => 'all'])->assertSuccessful();
    Mail::assertSentCount(11);

    $this->artisan('toymart:alerts:test', ['--list' => true])->assertSuccessful()->expectsOutputToContain('courier_webhook_silence');
    $this->artisan('toymart:alerts:test', ['name' => 'nope'])->assertExitCode(2);
});

it('reports failure when the test alert reaches nobody', function (): void {
    config(['monitoring.alerts.channels.mail' => [], 'monitoring.alerts.channels.sms' => []]);

    $this->artisan('toymart:alerts:test', ['name' => 'disk_space'])->assertFailed();
});

it('renders the alert mail in English and Bangla', function (): void {
    foreach (['en', 'bn'] as $locale) {
        $html = (new MonitoringAlertMail('backup_stale', 'critical', 'firing', 'summary text', '2026-12-10T00:00:00+00:00', true, $locale))->render();
        expect($html)->toContain('summary text')->toContain(__('monitoring.names.backup_stale', [], $locale));
    }
});

it('schedules the alert run every five minutes and the synthetic check every fifteen', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events());
    $find = fn (string $cmd) => $events->first(fn ($e): bool => str_contains((string) $e->command, $cmd));

    expect($find('toymart:alerts:run')->expression)->toBe('*/5 * * * *')
        ->and($find('toymart:synthetic:cart')->expression)->toBe('*/15 * * * *')
        ->and($find('toymart:scheduler:heartbeat')->expression)->toBe('* * * * *');
});
