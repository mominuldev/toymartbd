<?php

declare(strict_types=1);

use App\Domain\Reporting\Actions\SendReportRun;
use App\Domain\Reporting\Enums\ReportFrequency;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Jobs\SendScheduledReport;
use App\Domain\Reporting\Mail\ScheduledReportMail;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Domain\Reporting\Schedules\ScheduleClock;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Scheduled report emails (P8): CRUD and validation, Dhaka send times and Saturday weeks,
 * exactly one send per (schedule, period) however often the sweep runs, and recipients
 * receiving only what their role may see.
 */

/** Dhaka wall-clock time → test clock. */
function dhakaNow(string $local): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($local, 'Asia/Dhaka')->utc());
}

function makeSchedule(array $attributes, array $recipients): ReportSchedule
{
    $schedule = ReportSchedule::query()->create([
        'name' => 'Owner digest', 'report_type' => 'sales', 'frequency' => 'daily', 'send_time' => '08:00',
        'locale' => 'en', 'format' => 'xlsx', 'is_active' => true, ...$attributes,
    ]);
    $schedule->recipients()->sync(array_map(fn (User $u): int => $u->id, $recipients));

    return $schedule;
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    dhakaNow('2026-11-01 09:00');
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Rina Manager'])->refresh();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Arif Accounts'])->refresh();
    $this->viewer = User::factory()->withRole(Role::Viewer)->create(['name' => 'Vik Viewer'])->refresh();
    $this->editor = User::factory()->withRole(Role::ContentEditor)->create(['name' => 'Eva Editor'])->refresh();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('sends a due daily report once per period, however often the sweep runs', function (): void {
    Mail::fake();
    $schedule = makeSchedule([], [$this->manager, $this->viewer]);

    dhakaNow('2026-11-03 07:59');
    $this->artisan('toymart:reports:send-scheduled')->assertSuccessful();
    // Before 08:00 on the 3rd the latest occurrence is the 2nd at 08:00 → the 1st's report.
    expect(ReportScheduleRun::query()->count())->toBe(1)
        ->and(ReportScheduleRun::query()->first()->period_from->toDateString())->toBe('2026-11-01');

    dhakaNow('2026-11-03 08:00');
    $this->artisan('toymart:reports:send-scheduled')->assertSuccessful();
    $this->artisan('toymart:reports:send-scheduled')->assertSuccessful();
    dhakaNow('2026-11-03 23:45');
    $this->artisan('toymart:reports:send-scheduled')->assertSuccessful();

    $runs = ReportScheduleRun::query()->orderBy('period_from')->get();
    expect($runs->map(fn ($r) => [$r->period_from->toDateString(), $r->period_to->toDateString(), $r->status])->all())->toBe([
        ['2026-11-01', '2026-11-01', ReportRunStatus::Sent],
        ['2026-11-02', '2026-11-02', ReportRunStatus::Sent],
    ])->and($runs[1]->recipients_sent)->toBe(2);

    // Two periods × two recipients — never more.
    Mail::assertSent(ScheduledReportMail::class, 4);
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m): bool => $m->hasTo($this->viewer->email) && $m->profitHidden && $m->attachmentName() === 'sales-report-2026-11-02-to-2026-11-02.xlsx');
    Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m): bool => $m->hasTo($this->manager->email) && ! $m->profitHidden);
});

it('never resends a period even when the job is dispatched twice or the run failed', function (): void {
    Mail::fake();
    $schedule = makeSchedule([], [$this->manager]);
    dhakaNow('2026-11-04 08:15');
    $this->artisan('toymart:reports:send-scheduled');
    $run = ReportScheduleRun::query()->firstOrFail();

    (new SendScheduledReport($run->id))->handle(app(SendReportRun::class));
    Mail::assertSent(ScheduledReportMail::class, 1);

    $run->forceFill(['status' => ReportRunStatus::Failed])->save();
    $this->artisan('toymart:reports:send-scheduled');
    expect(ReportScheduleRun::query()->count())->toBe(1);
    Mail::assertSent(ScheduledReportMail::class, 1);
});

it('runs weekly on Saturday for the Saturday–Friday week and monthly on the 1st for the month before', function (): void {
    Mail::fake();
    Queue::fake();
    $weekly = makeSchedule(['frequency' => 'weekly', 'send_time' => '09:30', 'report_type' => 'pnl', 'locale' => 'bn', 'format' => 'csv'], [$this->accountant]);
    $monthly = makeSchedule(['frequency' => 'monthly', 'send_time' => '07:00', 'report_type' => 'couriers'], [$this->manager]);

    // Fri 13 Nov 23:50 Dhaka: the latest Saturday 09:30 is 7 Nov → week 31 Oct – 6 Nov.
    dhakaNow('2026-11-13 23:50');
    $this->artisan('toymart:reports:send-scheduled');
    // Sat 14 Nov 09:30 Dhaka (03:30 UTC) → week 7–13 Nov. The monthly schedule was created on
    // 1 Nov at 09:00, after that day's 07:00 send, so October's report is never backfilled.
    dhakaNow('2026-11-14 09:30');
    $this->artisan('toymart:reports:send-scheduled');

    $weeks = ReportScheduleRun::query()->where('report_schedule_id', $weekly->id)->orderBy('period_from')->get()
        ->map(fn ($r) => $r->period_from->toDateString().'..'.$r->period_to->toDateString())->all();
    expect($weeks)->toBe(['2026-10-31..2026-11-06', '2026-11-07..2026-11-13'])
        ->and(ReportScheduleRun::query()->where('report_schedule_id', $monthly->id)->count())->toBe(0);
    Queue::assertPushedOn('reports', SendScheduledReport::class);

    dhakaNow('2026-12-01 07:00');
    $this->artisan('toymart:reports:send-scheduled');
    $month = ReportScheduleRun::query()->where('report_schedule_id', $monthly->id)->sole();
    expect($month->period_from->toDateString().'..'.$month->period_to->toDateString())->toBe('2026-11-01..2026-11-30');
});

it('does not backfill an occurrence from before the schedule existed', function (): void {
    dhakaNow('2026-11-05 10:00');
    $schedule = makeSchedule(['send_time' => '08:00'], [$this->manager]);

    expect(ScheduleClock::due($schedule, CarbonImmutable::now('UTC')))->toBeNull()
        ->and(ScheduleClock::next($schedule, CarbonImmutable::now('UTC'))->toIso8601String())->toBe('2026-11-06T02:00:00+00:00');
});

it('skips a recipient who lost the permission and records the run as skipped when nobody is left', function (): void {
    Mail::fake();
    $schedule = makeSchedule(['report_type' => 'pnl'], [$this->accountant, $this->viewer]);
    $this->accountant->forceFill(['is_active' => false])->save();

    dhakaNow('2026-11-02 08:00');
    $this->artisan('toymart:reports:send-scheduled');

    $run = ReportScheduleRun::query()->sole();
    expect($run->status)->toBe(ReportRunStatus::Skipped)
        ->and($run->recipients_skipped)->toBe(2)
        ->and($run->recipients_sent)->toBe(0);
    Mail::assertNothingSent();
});

it('renders the email in Bangla with Western numerals and attaches the report', function (): void {
    $schedule = makeSchedule(['locale' => 'bn', 'format' => 'csv'], [$this->manager]);
    dhakaNow('2026-11-02 08:00');
    Mail::fake();
    $this->artisan('toymart:reports:send-scheduled');

    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'বিক্রয় রিপোর্ট')
            && str_contains($html, '2026-11-01')
            && str_contains($html, '৳0')
            && ! preg_match('/[০-৯]/u', $html)
            && $mail->attachmentName() === 'sales-report-2026-11-01-to-2026-11-01.csv';
    });
});

it('creates, updates, lists and deletes a schedule through the API', function (): void {
    $payload = [
        'name' => 'Weekly P&L', 'report_type' => 'pnl', 'frequency' => 'weekly', 'send_time' => '09:15',
        'locale' => 'bn', 'format' => 'xlsx', 'is_active' => true, 'recipient_ids' => [$this->accountant->id, $this->manager->id],
    ];
    $id = $this->actingAs($this->manager)->postJson('/api/v1/admin/reports/schedules', $payload)
        ->assertCreated()
        ->assertJsonPath('report_type', 'pnl')
        ->assertJsonPath('recipients.0.name', 'Arif Accounts')
        ->assertJsonMissingPath('recipients.0.email')
        // Created Sun 1 Nov 09:00 Dhaka → next Saturday 7 Nov 09:15 Dhaka.
        ->assertJsonPath('next_send_at', '2026-11-07T03:15:00+00:00')
        ->json('id');

    $this->actingAs($this->manager)->putJson("/api/v1/admin/reports/schedules/{$id}", [...$payload, 'is_active' => false, 'recipient_ids' => [$this->accountant->id]])
        ->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('next_send_at', null)->assertJsonCount(1, 'recipients');
    $this->actingAs($this->accountant)->getJson('/api/v1/admin/reports/schedules')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($this->accountant)->getJson("/api/v1/admin/reports/schedules/{$id}/runs")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->manager)->getJson('/api/v1/admin/reports/schedules/recipients?report_type=pnl')->assertOk()
        ->assertJsonPath('data.*.name', ['Arif Accounts', 'Rina Manager']);
    $this->actingAs($this->manager)->deleteJson("/api/v1/admin/reports/schedules/{$id}")->assertNoContent();
    expect(ReportSchedule::query()->count())->toBe(0);
});

it('refuses recipients who may not see the report and off-quarter send times with 422', function (): void {
    $base = ['name' => 'P&L', 'report_type' => 'pnl', 'frequency' => 'daily', 'send_time' => '08:00', 'locale' => 'en', 'format' => 'csv', 'is_active' => true];

    $this->actingAs($this->manager)->postJson('/api/v1/admin/reports/schedules', [...$base, 'recipient_ids' => [$this->viewer->id]])
        ->assertUnprocessable()->assertJsonPath('errors.0', ['field' => 'recipient_ids', 'message' => 'Vik Viewer is inactive or not allowed to see this report.']);
    $this->actingAs($this->manager)->postJson('/api/v1/admin/reports/schedules', [...$base, 'send_time' => '08:10', 'recipient_ids' => [$this->manager->id]])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'send_time');
    $this->actingAs($this->manager)->postJson('/api/v1/admin/reports/schedules', [...$base, 'recipient_ids' => []])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'recipient_ids');
    $this->actingAs($this->manager)->getJson('/api/v1/admin/reports/schedules/recipients?report_type=nope')->assertStatus(400);
});

it('exposes each frequency period exactly', function (string $frequency, string $sendDate, string $from, string $to): void {
    $range = ReportFrequency::from($frequency)->periodFor(CarbonImmutable::parse($sendDate, 'UTC'));

    expect([$range->fromDate(), $range->toDate()])->toBe([$from, $to]);
})->with([
    'daily' => ['daily', '2026-03-01', '2026-02-28', '2026-02-28'],
    'weekly' => ['weekly', '2026-11-14', '2026-11-07', '2026-11-13'],
    'monthly, leap February' => ['monthly', '2028-03-01', '2028-02-01', '2028-02-29'],
    'monthly across a year' => ['monthly', '2027-01-01', '2026-12-01', '2026-12-31'],
]);
