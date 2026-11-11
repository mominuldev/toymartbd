<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://meili.test:7700']);
});

it('reports ok with 200 when every dependency is up', function (): void {
    Http::fake(['meili.test:7700/health' => Http::response(['status' => 'available'])]);

    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonPath('checks.queue.status', 'ok')
        ->assertJsonPath('checks.queue.size', 0)
        ->assertJsonPath('checks.meilisearch.status', 'ok')
        ->assertJsonPath('last_courier_sync', null)
        ->assertJsonStructure(['status', 'checks' => ['database' => ['status', 'latency_ms']], 'checked_at']);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'http://meili.test:7700/health');
});

it('reports degraded but stays 200 when Meilisearch is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to meili.test'));

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.meilisearch.status', 'fail')
        ->assertJsonPath('checks.database.status', 'ok');

    // The public body never carries the error text or the host.
    expect($response->getContent())->not->toContain('cURL')->not->toContain('meili.test');
});

it('reports degraded when Meilisearch answers but is not available', function (): void {
    Http::fake(['*' => Http::response(['status' => 'unavailable'], 503)]);

    $this->getJson('/api/v1/health')
        ->assertStatus(200)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.meilisearch.status', 'fail');
});

it('reports degraded when the cache is down', function (): void {
    Http::fake(['*' => Http::response(['status' => 'available'])]);
    Cache::shouldReceive('put')->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));

    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.cache.status', 'fail');
    expect($response->getContent())->not->toContain('redis:6379');
});

it('reports degraded when the database is down', function (): void {
    Http::fake(['*' => Http::response(['status' => 'available'])]);
    DB::shouldReceive('connection->select')->andThrow(new RuntimeException('SQLSTATE[HY000] [2002]'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('checks.database.status', 'fail');
});

it('skips Meilisearch when Scout is not using it', function (): void {
    config(['scout.driver' => 'collection']);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.meilisearch.status', 'skipped');

    Http::assertNothingSent();
});
