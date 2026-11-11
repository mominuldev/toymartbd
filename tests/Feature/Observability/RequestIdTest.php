<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class RecordRequestIdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?string $seen = null;

    public function handle(): void
    {
        $id = Context::get('request_id');
        self::$seen = is_string($id) ? $id : null;
    }
}

beforeEach(function (): void {
    RecordRequestIdJob::$seen = null;
    Route::middleware('api')->get('api/v1/_test/request-id', function () {
        RecordRequestIdJob::dispatch()->onConnection('database');

        return response()->json(['context' => Context::get('request_id')]);
    });
});

it('generates a UUIDv7 when the request has no X-Request-Id', function (): void {
    $response = $this->getJson('api/v1/_test/request-id')->assertOk();

    $id = $response->headers->get('X-Request-Id');

    expect($id)->not->toBeNull()
        ->and(Str::isUuid((string) $id))->toBeTrue()
        ->and(substr((string) $id, 14, 1))->toBe('7')
        ->and($response->json('context'))->toBe($id);
});

it('keeps a well-formed incoming X-Request-Id', function (): void {
    $this->getJson('api/v1/_test/request-id', ['X-Request-Id' => 'edge-01J9ZK3.abc_def'])
        ->assertOk()
        ->assertHeader('X-Request-Id', 'edge-01J9ZK3.abc_def')
        ->assertJsonPath('context', 'edge-01J9ZK3.abc_def');
});

it('replaces a malformed incoming X-Request-Id', function (string $bad): void {
    $id = $this->getJson('api/v1/_test/request-id', ['X-Request-Id' => $bad])
        ->assertOk()
        ->headers->get('X-Request-Id');

    expect($id)->not->toBe($bad)->and(Str::isUuid((string) $id))->toBeTrue();
})->with([
    'too short' => ['abc'],
    'spaces' => ['has spaces in it'],
    'markup' => ['<script>alert(1)</script>'],
    'too long' => [str_repeat('a', 129)],
]);

it('returns the request id on every response, including the health check', function (): void {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'uptime-probe-0001'])
        ->assertHeader('X-Request-Id', 'uptime-probe-0001');
});

it('puts the request id in RFC 9457 problem bodies', function (): void {
    $this->getJson('api/v1/storefront/does-not-exist', ['X-Request-Id' => 'support-ticket-42'])
        ->assertNotFound()
        ->assertHeader('X-Request-Id', 'support-ticket-42')
        ->assertJsonPath('request_id', 'support-ticket-42')
        ->assertJsonPath('status', 404);
});

it('propagates the request id into a queued job run by a worker', function (): void {
    $this->getJson('api/v1/_test/request-id', ['X-Request-Id' => 'trace-into-worker-1'])->assertOk();

    expect(DB::table('jobs')->count())->toBe(1);

    // The worker starts with an empty Context, as a separate process would.
    Context::flush();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    expect(RecordRequestIdJob::$seen)->toBe('trace-into-worker-1')
        ->and(DB::table('jobs')->count())->toBe(0);
});
