<?php

declare(strict_types=1);

use App\Domain\Tracking\Actions\RecordTrackingEvent;
use App\Domain\Tracking\Actions\SendTestTrackingEvent;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\TrackingEventRecord;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Enums\TrackingSource;
use App\Domain\Tracking\Exceptions\RetryableTrackingFailure;
use App\Domain\Tracking\Jobs\DispatchTrackingEvent;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Services\DispatchPolicy;
use App\Domain\Tracking\Services\TrackingDestinations;
use App\Domain\Tracking\Services\TrackingEventDataFactory;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Tracking\Support\FakeTrackingDestination;

/*
 * DispatchTrackingEvent + DispatchPolicy (SYSTEM-ARCHITECTURE §7, docs/api/tracking.md §1).
 */

function recordServerEvent(TrackingEventName $name = TrackingEventName::Purchase, ?ConsentState $consent = null): TrackingEvent
{
    return app(RecordTrackingEvent::class)->handle(new TrackingEventRecord(
        eventId: (string) Str::uuid(),
        name: $name,
        source: TrackingSource::Server,
        occurredAt: CarbonImmutable::now(),
        consent: $consent ?? new ConsentState(true, true, 'opt_in'),
        value: '1500.00',
        currency: 'BDT',
        transactionId: 'TMB-26-00001',
    ))['event'];
}

it('encodes the dedup matrix in one place', function (string $name, string $source, bool $ga4, bool $meta): void {
    $n = TrackingEventName::from($name);
    $s = TrackingSource::from($source);

    expect(DispatchPolicy::applies($n, $s, 'ga4'))->toBe($ga4)
        ->and(DispatchPolicy::applies($n, $s, 'meta'))->toBe($meta);
})->with([
    // name, source, server→GA4, server→Meta
    ['page_view', 'browser', false, false],
    ['view_item', 'browser', false, true],
    ['add_to_cart', 'browser', false, true],
    ['begin_checkout', 'browser', false, true],
    ['purchase', 'server', true, true],
    ['refund', 'server', true, false],
]);

it('drops an event older than one hour as skipped_stale and sends nothing', function (): void {
    $fakes = FakeTrackingDestination::install();
    $this->travelTo(now()->subMinutes(61));
    $row = recordServerEvent();
    $this->travelBack();

    (new DispatchTrackingEvent($row->id))->handle(app(TrackingDestinations::class), app(DispatchPolicy::class), app(TrackingEventDataFactory::class));

    $row->refresh();
    expect($row->ga4_status)->toBe(DispatchStatus::SkippedStale)
        ->and($row->meta_status)->toBe(DispatchStatus::SkippedStale)
        ->and($row->dispatched_at)->not->toBeNull()
        ->and($fakes['ga4']->sent)->toBe([])
        ->and($fakes['meta']->sent)->toBe([]);
});

it('never retries past one hour', function (): void {
    $row = recordServerEvent();

    expect((new DispatchTrackingEvent($row->id))->retryUntil()->getTimestamp())
        ->toBe($row->created_at->addHour()->getTimestamp());
});

it('retries only the destination that failed retryably, and never re-sends the one that succeeded', function (): void {
    $fakes = FakeTrackingDestination::install();
    $fakes['meta']->retryableFailures = 1;
    $row = recordServerEvent();
    $job = new DispatchTrackingEvent($row->id);
    $run = fn () => $job->handle(app(TrackingDestinations::class), app(DispatchPolicy::class), app(TrackingEventDataFactory::class));

    expect($run)->toThrow(RetryableTrackingFailure::class);
    $row->refresh();
    expect($row->ga4_status)->toBe(DispatchStatus::Sent)
        ->and($row->meta_status)->toBe(DispatchStatus::Pending)
        ->and($row->meta_attempts)->toBe(1)
        ->and($row->meta_response['http_status'])->toBe(503)
        ->and($row->dispatched_at)->toBeNull();

    $run();
    $row->refresh();
    expect($row->meta_status)->toBe(DispatchStatus::Sent)
        ->and($row->meta_attempts)->toBe(2)
        ->and($row->ga4_attempts)->toBe(1)
        ->and($fakes['ga4']->sent)->toHaveCount(1)
        ->and($fakes['meta']->sent)->toHaveCount(1)
        ->and($row->dispatched_at)->not->toBeNull();
});

it('marks what is still pending as failed when the job gives up', function (): void {
    FakeTrackingDestination::install();
    $row = recordServerEvent();

    (new DispatchTrackingEvent($row->id))->failed(new RetryableTrackingFailure('HTTP 503', 'meta', 503));

    expect($row->refresh()->ga4_status)->toBe(DispatchStatus::Failed)
        ->and($row->meta_status)->toBe(DispatchStatus::Failed);
});

it('gates each destination on its own consent purpose', function (): void {
    $fakes = FakeTrackingDestination::install();
    // Analytics yes, ads no: GA4 gets the purchase, Meta does not.
    $row = recordServerEvent(consent: new ConsentState(true, false, 'opt_in'));
    DispatchTrackingEvent::dispatchSync($row->id);

    expect($row->refresh()->ga4_status)->toBe(DispatchStatus::Sent)
        ->and($row->meta_status)->toBe(DispatchStatus::SkippedConsent)
        ->and($fakes['meta']->sent)->toBe([]);
});

it('keeps tracking_events append-only', function (): void {
    $row = recordServerEvent();

    expect(fn () => $row->update(['event_name' => 'refund']))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

it('sends a test event through the validation path without recording a row', function (): void {
    $fakes = FakeTrackingDestination::install();

    $result = app(SendTestTrackingEvent::class)->handle('ga4');

    expect($result->status)->toBe(DispatchStatus::Sent)
        ->and($fakes['ga4']->validated)->toHaveCount(1)
        ->and($fakes['ga4']->sent)->toBe([])
        ->and(TrackingEvent::query()->count())->toBe(0);
});

it('reports an unconfigured destination on the test send', function (): void {
    FakeTrackingDestination::install(configured: false);

    expect(app(SendTestTrackingEvent::class)->handle('meta')->status)->toBe(DispatchStatus::SkippedDisabled);
});

it('falls back to env when nothing is saved, and the saved value wins', function (): void {
    config(['tracking.ga4.measurement_id' => 'G-FROMENV1', 'tracking.purchase_trigger' => 'order_delivered']);
    $settings = app(TrackingSettings::class);
    expect($settings->ga4MeasurementId())->toBe('G-FROMENV1')
        ->and($settings->purchaseTrigger()->value)->toBe('order_delivered')
        ->and($settings->consentMode())->toBe('opt_in');

    Setting::put(TrackingSettings::GA4_MEASUREMENT_ID, 'G-SAVED001', SettingGroup::Tracking);
    expect($settings->ga4MeasurementId())->toBe('G-SAVED001')
        ->and($settings->source(TrackingSettings::GA4_MEASUREMENT_ID))->toBe('settings');
});
