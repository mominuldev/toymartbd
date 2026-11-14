<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use App\Models\SystemHeartbeat;
use Illuminate\Support\Facades\Schema;

/*
 * system_heartbeats (P10): the single "last succeeded" record the health endpoint, the alerts
 * and toymart:launch:check read.
 */

it('records the scheduler heartbeat from toymart:scheduler:heartbeat', function (): void {
    $this->artisan('toymart:scheduler:heartbeat')->assertSuccessful();

    expect(SystemHeartbeat::lastSuccess(SystemHeartbeat::SCHEDULER)?->diffInSeconds(now(), true))->toBeLessThan(5);
});

it('records a courier heartbeat whenever the provider answers', function (): void {
    app(CourierCircuitBreaker::class)->recordSuccess('steadfast');

    expect(SystemHeartbeat::lastSuccess('courier.steadfast'))->not->toBeNull()
        ->and(SystemHeartbeat::latestSuccessWithPrefix(SystemHeartbeat::COURIER_PREFIX))->not->toBeNull();
});

it('overwrites in place and keeps success and failure apart', function (): void {
    SystemHeartbeat::beat('backup', ['backup' => 'toymartbd-1']);
    $this->travel(1)->hours();
    SystemHeartbeat::failed('backup', 'RuntimeException');

    $row = SystemHeartbeat::query()->findOrFail('backup');
    expect(SystemHeartbeat::query()->count())->toBe(1)
        ->and($row->last_failure_at->greaterThan($row->last_success_at))->toBeTrue()
        ->and($row->last_error)->toBe('RuntimeException')
        ->and($row->meta)->toBe(['backup' => 'toymartbd-1']);
});

it('never throws, so monitoring cannot break the duty it watches', function (): void {
    Schema::drop('system_heartbeats');

    SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);
    app(CourierCircuitBreaker::class)->recordSuccess('pathao');

    expect(SystemHeartbeat::lastSuccess(SystemHeartbeat::SCHEDULER))->toBeNull();
});
