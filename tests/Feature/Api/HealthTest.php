<?php

declare(strict_types=1);

use App\Http\Health\HealthChecker;
use App\Models\SystemHeartbeat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * GET /api/v1/health (SYSTEM-ARCHITECTURE §11, P10): public body is status only; detail behind
 * X-Health-Token; ok / degraded (200) / down (503); cached briefly.
 */

const HEALTH_TOKEN = 'test-health-token-0123456789';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'scout.driver' => 'meilisearch',
        'scout.meilisearch.host' => 'http://meili.test:7700',
        'monitoring.health.detail_token' => HEALTH_TOKEN,
        'monitoring.health.cache_seconds' => 0,
    ]);
});

function healthDetail(): Illuminate\Testing\TestResponse
{
    return test()->getJson('/api/v1/health', ['X-Health-Token' => HEALTH_TOKEN]);
}

it('answers the public with the overall status only', function (): void {
    Http::fake(['meili.test:7700/health' => Http::response(['status' => 'available'])]);

    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('status', 'ok')
        ->assertJsonMissingPath('checks');
    expect(array_keys($response->json()))->toBe(['status', 'checked_at']);
});

it('withholds detail for a wrong token, and when no token is configured', function (): void {
    Http::fake(['*' => Http::response(['status' => 'available'])]);

    $this->getJson('/api/v1/health', ['X-Health-Token' => 'wrong'])->assertOk()->assertJsonMissingPath('checks');

    config(['monitoring.health.detail_token' => null]);
    $this->getJson('/api/v1/health', ['X-Health-Token' => ''])->assertOk()->assertJsonMissingPath('checks');
});

it('reports every §11 dependency in the detail', function (): void {
    Http::fake(['meili.test:7700/health' => Http::response(['status' => 'available'])]);
    SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);
    SystemHeartbeat::beat(SystemHeartbeat::BACKUP);
    SystemHeartbeat::beat(SystemHeartbeat::COURIER_PREFIX.'pathao');

    $response = healthDetail();

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'skipped')
        ->assertJsonPath('checks.queue.status', 'ok')
        ->assertJsonPath('checks.queue.depths.critical', 0)
        ->assertJsonPath('checks.queue.depths.courier', 0)
        ->assertJsonPath('checks.queue.depths.notify', 0)
        ->assertJsonPath('checks.queue.threshold', 500)
        ->assertJsonPath('checks.horizon.status', 'skipped')
        ->assertJsonPath('checks.meilisearch.status', 'ok')
        ->assertJsonPath('checks.scheduler.status', 'ok')
        ->assertJsonPath('checks.courier_sync.status', 'ok')
        ->assertJsonPath('checks.backup.status', 'ok')
        ->assertJsonStructure(['checks' => ['database' => ['status', 'latency_ms'], 'courier_sync' => ['last_success_at'], 'backup' => ['last_success_at']]]);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'http://meili.test:7700/health');
});

it('is degraded, still 200, when Meilisearch is unreachable — and the body leaks nothing', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to meili.test'));

    $response = healthDetail();

    $response->assertStatus(200)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.meilisearch.status', 'fail')
        ->assertJsonPath('checks.database.status', 'ok');
    expect($response->getContent())->not->toContain('cURL')->not->toContain('meili.test');
});

it('is degraded when Meilisearch answers but is not available', function (): void {
    Http::fake(['*' => Http::response(['status' => 'unavailable'], 503)]);

    healthDetail()->assertStatus(200)->assertJsonPath('status', 'degraded')->assertJsonPath('checks.meilisearch.status', 'fail');
});

it('is down with 503 when the cache is down, without leaking the host', function (): void {
    Http::fake(['*' => Http::response(['status' => 'available'])]);
    Cache::shouldReceive('put')->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));

    $response = healthDetail();

    $response->assertStatus(503)
        ->assertJsonPath('status', 'down')
        ->assertJsonPath('checks.cache.status', 'fail');
    expect($response->getContent())->not->toContain('redis:6379');
});

it('is down with 503 when the database is down — publicly too', function (): void {
    Http::fake(['*' => Http::response(['status' => 'available'])]);
    DB::shouldReceive('connection->select')->andThrow(new RuntimeException('SQLSTATE[HY000] [2002]'));

    $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('status', 'down')->assertJsonMissingPath('checks');
});

it('skips Meilisearch and the index-lag result when Scout is not using it', function (): void {
    config(['scout.driver' => 'collection']);

    healthDetail()
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.meilisearch.status', 'skipped')
        ->assertJsonPath('checks.search_index.status', 'skipped');

    Http::assertNothingSent();
});

it('is degraded when a queue is deeper than the threshold', function (): void {
    config(['scout.driver' => 'collection', 'queue.default' => 'database', 'monitoring.alerts.queue_depth.threshold' => 2]);
    foreach (range(1, 3) as $i) {
        DB::table('jobs')->insert(['queue' => 'notify', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
    }

    healthDetail()
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.queue.status', 'fail')
        ->assertJsonPath('checks.queue.depths.notify', 3)
        ->assertJsonPath('checks.queue.threshold', 2);
});

it('is degraded when the scheduler or the backup heartbeat is stale', function (): void {
    config(['scout.driver' => 'collection']);
    SystemHeartbeat::beat(SystemHeartbeat::SCHEDULER);
    SystemHeartbeat::beat(SystemHeartbeat::BACKUP);
    $this->travel(27)->hours();

    healthDetail()
        ->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.scheduler.status', 'fail')
        ->assertJsonPath('checks.backup.status', 'fail');
});

it('treats a never-recorded heartbeat as skipped locally and as a failure where heartbeats are expected', function (): void {
    config(['scout.driver' => 'collection']);

    healthDetail()->assertJsonPath('checks.scheduler.status', 'skipped')->assertJsonPath('checks.backup.status', 'skipped');

    config(['monitoring.health.expect_heartbeats' => true]);
    healthDetail()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.scheduler.status', 'fail')->assertJsonPath('checks.backup.status', 'fail');
});

it('is degraded when the index-lag check last found lag', function (): void {
    Http::fake(['*' => Http::response(['status' => 'available'])]);
    Cache::put(App\Domain\Search\Services\IndexLagCheck::CACHE_KEY, ['ok' => false, 'checked_at' => now()->toIso8601String()], 600);

    healthDetail()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.search_index.status', 'fail');
});

it('reuses a computed report for the cache window so polling cannot load the database', function (): void {
    config(['scout.driver' => 'collection', 'monitoring.health.cache_seconds' => 10]);

    $this->getJson('/api/v1/health')->assertOk();
    expect(Cache::get(HealthChecker::CACHE_KEY))->toBeArray();

    DB::enableQueryLog();
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'select 1') || str_contains($q, 'system_heartbeats')))->toBeEmpty();
});
