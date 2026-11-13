<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Jobs\PollShipmentStatuses;
use App\Domain\Fulfilment\Jobs\ProcessCourierWebhook;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Tests\Feature\Fulfilment\Tracking\CourierTrackingKit as Kit;

/*
 * Phase 9 coverage (docs/audits/SEO-AND-COVERAGE.md, Task B): the retry branches of the two
 * courier status jobs. A webhook whose re-query cannot reach the provider stays open and the
 * job releases itself on its backoff schedule ([30, 120, 300, 900] s) until its 5th try, after
 * which the 15-minute poll owns it; a later successful attempt closes the receipt once. The
 * poll chunk stops at the first sign the provider is down rather than hammering it.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00', 'couriers.circuit_breaker.failure_threshold' => 50]);
    CourierProvider::query()->where('code', 'steadfast')->update(['supports_webhook' => true]);
    $this->gateway = Kit::gateway();
    $this->shipment = Kit::bookedShipment($this->gateway);
});

it('releases a deferred webhook on its backoff schedule and stops after the last try', function (int $attempt, ?int $delay): void {
    $this->gateway->willReportStatus('CN-1001', new RuntimeException('timeout'));
    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'retry-1'])->assertOk();
    $receipt = CourierWebhookReceipt::query()->sole();

    $job = (new ProcessCourierWebhook($receipt->id))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(app(RefreshShipmentStatus::class));

    if ($delay === null) {
        $job->assertNotReleased();
    } else {
        $job->assertReleased($delay);
    }
    expect($receipt->refresh()->processed_at)->toBeNull()
        ->and($receipt->result)->toBe('deferred')
        ->and($this->shipment->refresh()->status)->toBe(ShipmentStatus::Booked);
})->with([
    'first try → 30 s' => [1, 30],
    'third try → 300 s' => [3, 300],
    'fourth try → 900 s' => [4, 900],
    'fifth (last) try → not released' => [5, null],
]);

it('closes the receipt once the provider answers on a retry; a replayed job is a no-op', function (): void {
    $this->gateway->willReportStatus('CN-1001', new RuntimeException('timeout'));
    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'retry-2'])->assertOk();
    $receipt = CourierWebhookReceipt::query()->sole();
    expect($receipt->processed_at)->toBeNull();

    $this->gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::InTransit, 'in_transit'));
    $job = (new ProcessCourierWebhook($receipt->id))->withFakeQueueInteractions();
    $job->handle(app(RefreshShipmentStatus::class));

    $job->assertNotReleased();
    expect($receipt->refresh()->processed_at)->not->toBeNull()
        ->and($this->shipment->refresh()->status)->toBe(ShipmentStatus::InTransit);

    $calls = count($this->gateway->statusCalls);
    (new ProcessCourierWebhook($receipt->id))->withFakeQueueInteractions()->handle(app(RefreshShipmentStatus::class));
    expect($this->gateway->statusCalls)->toHaveCount($calls);
});

it('ignores a receipt that no longer exists', function (): void {
    $job = (new ProcessCourierWebhook(987654321))->withFakeQueueInteractions();
    $job->handle(app(RefreshShipmentStatus::class));

    $job->assertNotReleased();
    expect($this->gateway->statusCalls)->toHaveCount(1); // only the booking answer from the kit
});

it('stops a poll chunk at an open circuit instead of calling the provider for every shipment', function (): void {
    $second = Kit::bookedShipment($this->gateway, consignment: 'CN-1002');
    config(['couriers.circuit_breaker.failure_threshold' => 1]);
    app(CourierCircuitBreaker::class)->recordFailure('steadfast');
    expect(app(CourierCircuitBreaker::class)->isOpen('steadfast'))->toBeTrue();
    $calls = count($this->gateway->statusCalls);

    (new PollShipmentStatuses([$this->shipment->id, $second->id]))->handle(app(RefreshShipmentStatus::class));

    expect($this->gateway->statusCalls)->toHaveCount($calls)
        ->and($this->shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($second->refresh()->status)->toBe(ShipmentStatus::Booked);
});
