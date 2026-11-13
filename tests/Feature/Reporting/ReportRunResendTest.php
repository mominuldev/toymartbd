<?php

declare(strict_types=1);

use App\Domain\Reporting\Enums\ReportDeliveryStatus;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Jobs\SendScheduledReport;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Domain\Reporting\Models\ReportScheduleRunDelivery;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Phase 8 closeout (P9 batch 1): each recipient's copy of a scheduled report run is recorded,
 * one failed copy no longer stops the rest, and staff can resend a failed run — every failed
 * copy, or one recipient's — for the same period: exactly once, permissions re-checked at
 * resend time, queued on `reports`, audited.
 */

/** Recipients whose mail the (array) transport refuses, by email. */
function rsFailFor(array $emails): void
{
    app()->instance('rs.failing', $emails);
}

/** Every address the array transport delivered to, in order. */
function rsDelivered(): array
{
    $to = [];
    foreach (app('mailer')->getSymfonyTransport()->messages() as $sent) {
        foreach ($sent->getEnvelope()->getRecipients() as $address) {
            $to[] = $address->getAddress();
        }
    }

    return $to;
}

function rsSchedule(array $recipients, array $attributes = []): ReportSchedule
{
    $schedule = ReportSchedule::query()->create([
        'name' => 'Owner digest', 'report_type' => 'sales', 'frequency' => 'daily', 'send_time' => '08:00',
        'locale' => 'en', 'format' => 'csv', 'is_active' => true, ...$attributes,
    ]);
    $schedule->recipients()->sync(array_map(fn (User $u): int => $u->id, $recipients));

    return $schedule;
}

/** Runs the sweep at 08:15 Dhaka on 4 Nov → the run for 3 Nov (sent synchronously in tests). */
function rsSweep(): ReportScheduleRun
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04 08:15', 'Asia/Dhaka')->utc());
    test()->artisan('toymart:reports:send-scheduled')->assertSuccessful();

    return ReportScheduleRun::query()->latest('id')->firstOrFail();
}

function rsCopies(ReportScheduleRun $run): array
{
    return ReportScheduleRunDelivery::query()->where('report_schedule_run_id', $run->id)->with('user')->orderBy('id')->get()
        ->mapWithKeys(fn (ReportScheduleRunDelivery $d): array => [$d->user->name => $d->status->value])->all();
}

function rsResend(ReportScheduleRun $run, array $body = [])
{
    return test()->postJson("/api/v1/admin/reports/schedules/{$run->report_schedule_id}/runs/{$run->id}/resend", $body);
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Dhaka')->utc());
    config(['mail.default' => 'array']);
    rsFailFor([]);
    Event::listen(MessageSending::class, function (MessageSending $event): void {
        foreach ($event->message->getTo() as $address) {
            if (in_array($address->getAddress(), app('rs.failing'), true)) {
                throw new TransportException("Expected response code 250 but got 550 for <{$address->getAddress()}>");
            }
        }
    });
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Rina Manager', 'email' => 'rina@example.test'])->refresh();
    $this->viewer = User::factory()->withRole(Role::Viewer)->create(['name' => 'Vik Viewer', 'email' => 'vik@example.test'])->refresh();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Arif Accounts', 'email' => 'arif@example.test'])->refresh();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('records each recipient\'s copy and keeps sending after one copy fails', function (): void {
    rsSchedule([$this->manager, $this->viewer, $this->accountant]);
    rsFailFor(['vik@example.test']);

    $run = rsSweep();

    expect($run->status)->toBe(ReportRunStatus::Failed)
        ->and($run->recipients_sent)->toBe(2)
        ->and(rsCopies($run))->toBe(['Arif Accounts' => 'sent', 'Rina Manager' => 'sent', 'Vik Viewer' => 'failed'])
        // Recipients go out in name order.
        ->and(rsDelivered())->toBe(['arif@example.test', 'rina@example.test'])
        // The error is kept for staff, with the address masked (rule 20).
        ->and($run->error)->toContain('TransportException')->toContain('[email]')->not->toContain('vik@example.test');
});

it('resends only the failed copy, once, and records who resent it', function (): void {
    rsSchedule([$this->manager, $this->viewer]);
    rsFailFor(['vik@example.test']);
    $run = rsSweep();
    rsFailFor([]);
    $this->actingAs($this->accountant);

    $response = rsResend($run)->assertStatus(202);

    $run->refresh();
    expect($run->status)->toBe(ReportRunStatus::Sent)
        ->and($run->recipients_sent)->toBe(2)
        ->and($run->error)->toBeNull()
        ->and($run->resend_count)->toBe(1)
        ->and($run->last_resent_by)->toBe($this->accountant->id)
        ->and(rsCopies($run))->toBe(['Rina Manager' => 'sent', 'Vik Viewer' => 'sent'])
        // Rina got the period's email once in all; Vik once, on the resend.
        ->and(rsDelivered())->toBe(['rina@example.test', 'vik@example.test']);
    $copy = ReportScheduleRunDelivery::query()->where('user_id', $this->viewer->id)->sole();
    expect($copy->attempts)->toBe(2)->and($copy->resent_by)->toBe($this->accountant->id);

    $audit = AuditSensitive::query()->where('action', AuditAction::ScheduledReportResent->value)->sole();
    expect($audit->user_id)->toBe($this->accountant->id)
        ->and($audit->model_id)->toBe($run->id)
        ->and($audit->after)->toMatchArray(['recipient_ids' => [$this->viewer->id], 'scope' => 'failed_copies', 'period_from' => '2026-11-03', 'period_to' => '2026-11-03'])
        ->and(json_encode($audit->after))->not->toContain('@');

    // The response is the run as queued; the sent-log shows each copy and the resender by name.
    expect($response->json('resend_count'))->toBe(1)->and($response->json('last_resent_by.name'))->toBe('Arif Accounts');
    $log = $this->getJson("/api/v1/admin/reports/schedules/{$run->report_schedule_id}/runs")->assertOk()->json('data.0');
    expect($log['status'])->toBe('sent')
        ->and(collect($log['deliveries'])->pluck('status', 'name')->all())->toBe(['Rina Manager' => 'sent', 'Vik Viewer' => 'sent'])
        ->and($log['deliveries'][1]['resent_by'])->toBe('Arif Accounts')
        ->and(json_encode($log))->not->toContain('@example.test');

    // Asking again: the run did not fail any more.
    rsResend($run)->assertStatus(409)->assertJsonPath('status', 409);
    expect(rsDelivered())->toHaveCount(2);
});

it('resends one recipient\'s copy and leaves the other failed copy failed', function (): void {
    rsSchedule([$this->manager, $this->viewer, $this->accountant]);
    rsFailFor(['vik@example.test', 'arif@example.test']);
    $run = rsSweep();
    rsFailFor([]);
    $this->actingAs($this->manager);

    rsResend($run, ['user_id' => $this->accountant->id])->assertStatus(202);

    $run->refresh();
    expect(rsCopies($run))->toEqual(['Rina Manager' => 'sent', 'Vik Viewer' => 'failed', 'Arif Accounts' => 'sent'])
        ->and($run->status)->toBe(ReportRunStatus::Failed)
        ->and(rsDelivered())->toBe(['rina@example.test', 'arif@example.test']);
    AuditSensitive::query()->where('action', AuditAction::ScheduledReportResent->value)->sole();

    // A copy that already went out is never mailed again.
    rsResend($run, ['user_id' => $this->manager->id])->assertStatus(409);
    rsResend($run, ['user_id' => $this->accountant->id])->assertStatus(409);
    expect(rsDelivered())->toHaveCount(2);
});

it('is idempotent: a second resend while the first is queued queues nothing more', function (): void {
    rsSchedule([$this->manager, $this->viewer]);
    rsFailFor(['vik@example.test']);
    $run = rsSweep();
    rsFailFor([]);
    $this->actingAs($this->manager);
    Queue::fake();

    rsResend($run)->assertStatus(202)->assertJsonPath('status', 'queued');
    rsResend($run)->assertStatus(202)->assertJsonPath('status', 'queued');
    rsResend($run, ['user_id' => $this->viewer->id])->assertStatus(202);

    Queue::assertPushedOn('reports', SendScheduledReport::class);
    Queue::assertPushed(SendScheduledReport::class, 1);
    expect($run->refresh()->resend_count)->toBe(1)
        ->and(AuditSensitive::query()->where('action', AuditAction::ScheduledReportResent->value)->count())->toBe(1);

    // The one job sends the failed copy; a duplicated job finds the run already handled.
    $job = Queue::pushed(SendScheduledReport::class)->first();
    $job->handle(app(App\Domain\Reporting\Actions\SendReportRun::class));
    $job->handle(app(App\Domain\Reporting\Actions\SendReportRun::class));
    expect(rsDelivered())->toBe(['rina@example.test', 'vik@example.test'])
        ->and($run->refresh()->status)->toBe(ReportRunStatus::Sent);
});

it('re-checks the recipient\'s permissions at resend time', function (): void {
    rsSchedule([$this->manager, $this->viewer]);
    rsFailFor(['vik@example.test']);
    $run = rsSweep();
    rsFailFor([]);
    $this->actingAs($this->manager);
    // Vik is deactivated after the failure.
    $this->viewer->forceFill(['is_active' => false])->save();

    rsResend($run, ['user_id' => $this->viewer->id])->assertUnprocessable()->assertJsonPath('errors.0.field', 'user_id');
    rsResend($run)->assertStatus(409);
    // Someone who is not on the schedule at all.
    rsResend($run, ['user_id' => $this->accountant->id])->assertUnprocessable();
    expect($run->refresh()->status)->toBe(ReportRunStatus::Failed)
        ->and(rsDelivered())->toBe(['rina@example.test'])
        ->and(AuditSensitive::query()->where('action', AuditAction::ScheduledReportResent->value)->count())->toBe(0);
});

it('skips at send time a recipient who lost the permission after the resend was queued', function (): void {
    rsSchedule([$this->manager, $this->viewer]);
    rsFailFor(['vik@example.test']);
    $run = rsSweep();
    rsFailFor([]);
    $this->actingAs($this->manager);
    Queue::fake();
    rsResend($run)->assertStatus(202);
    $this->viewer->forceFill(['is_active' => false])->save();

    Queue::pushed(SendScheduledReport::class)->first()->handle(app(App\Domain\Reporting\Actions\SendReportRun::class));

    expect(rsCopies($run))->toBe(['Rina Manager' => 'sent', 'Vik Viewer' => 'skipped'])
        ->and($run->refresh()->status)->toBe(ReportRunStatus::Sent)
        ->and(rsDelivered())->toBe(['rina@example.test']);
});

it('refuses a whole resend of an older run with no per-recipient record, but resends one copy', function (): void {
    $schedule = rsSchedule([$this->manager, $this->viewer]);
    // A run failed before P9: one copy went out, nobody knows whose.
    $run = ReportScheduleRun::query()->create(['report_schedule_id' => $schedule->id, 'period_from' => '2026-10-30', 'period_to' => '2026-10-30', 'status' => ReportRunStatus::Failed, 'recipients_sent' => 1, 'error' => 'TransportException: timeout']);
    $this->actingAs($this->manager);

    rsResend($run)->assertStatus(409);
    rsResend($run, ['user_id' => $this->viewer->id])->assertStatus(202);

    expect(rsDelivered())->toBe(['vik@example.test'])
        ->and(rsCopies($run->refresh()))->toBe(['Vik Viewer' => 'sent'])
        ->and($run->status)->toBe(ReportRunStatus::Sent);
});

it('refuses a run from another schedule and an unknown body field type', function (): void {
    $a = rsSchedule([$this->manager]);
    $b = rsSchedule([$this->manager], ['name' => 'Other']);
    $run = ReportScheduleRun::query()->create(['report_schedule_id' => $b->id, 'period_from' => '2026-10-30', 'period_to' => '2026-10-30', 'status' => ReportRunStatus::Failed]);
    $this->actingAs($this->manager);

    $this->postJson("/api/v1/admin/reports/schedules/{$a->id}/runs/{$run->id}/resend")->assertNotFound();
    rsResend($run, ['user_id' => 'everyone'])->assertUnprocessable();
});

it('lets only someone who can open the report resend it', function (): void {
    $schedule = rsSchedule([$this->accountant], ['report_type' => 'pnl']);
    $run = ReportScheduleRun::query()->create(['report_schedule_id' => $schedule->id, 'period_from' => '2026-10-30', 'period_to' => '2026-10-30', 'status' => ReportRunStatus::Failed]);
    // reports.view + reports.export but no finance.view: may manage schedules, not resend a P&L.
    $exporter = User::factory()->create()->refresh();
    $exporter->givePermissionTo(['reports.view', 'reports.export']);
    $this->actingAs($exporter);

    rsResend($run)->assertUnprocessable();
    expect(ReportScheduleRunDelivery::query()->count())->toBe(0);
    expect(ReportDeliveryStatus::cases())->toHaveCount(4);
});
