<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureIdempotency;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

const IDEM_URI = '/api/v1/storefront/_test/idempotent';

beforeEach(function (): void {
    $this->executions = 0;
    $counter = &$this->executions;

    Route::middleware(['api', 'idempotent'])->group(function () use (&$counter): void {
        Route::post(ltrim(IDEM_URI, '/'), function (Request $request) use (&$counter) {
            $counter++;

            return response()->json([
                'execution' => $counter,
                'nonce' => bin2hex(random_bytes(8)),
                'echo' => $request->all(),
            ], 201)->header('X-Custom', 'kept')->cookie('tracker', 'x');
        });

        Route::post('api/v1/storefront/_test/idempotent-fails', function () use (&$counter): void {
            $counter++;

            abort(503);
        });

        Route::post('api/v1/storefront/_test/idempotent-rejects', function () use (&$counter): void {
            $counter++;

            abort(409, 'Out of stock.');
        });
    });
});

it('replays the original response byte-identical without re-executing', function (): void {
    $headers = ['Idempotency-Key' => 'key-0000-replay'];

    $first = $this->postJson(IDEM_URI, ['qty' => 2, 'sku' => 'A'], $headers)->assertCreated();
    $second = $this->postJson(IDEM_URI, ['sku' => 'A', 'qty' => 2], $headers); // same body, keys reordered

    expect($this->executions)->toBe(1);
    $second->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertHeader('X-Custom', 'kept')
        ->assertHeader('Content-Type', 'application/json');
    expect($second->getContent())->toBe($first->getContent());
    expect($second->headers->getCookies())->toBeEmpty();
    expect($first->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('rejects the same key with a different body as idempotency-key-reused', function (): void {
    $headers = ['Idempotency-Key' => 'key-0000-reused'];

    $this->postJson(IDEM_URI, ['qty' => 1], $headers)->assertCreated();
    $this->postJson(IDEM_URI, ['qty' => 2], $headers)
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/idempotency-key-reused');

    expect($this->executions)->toBe(1);
});

it('requires the header on a marked route', function (): void {
    $this->postJson(IDEM_URI, ['qty' => 1])
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/idempotency-key-missing');

    expect($this->executions)->toBe(0);
});

it('rejects a malformed key', function (string $key): void {
    $this->postJson(IDEM_URI, [], ['Idempotency-Key' => $key])
        ->assertStatus(400)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/idempotency-key-invalid');

    expect($this->executions)->toBe(0);
})->with([
    'too short' => 'short',
    'contains a space' => 'has a space in it',
    'too long' => str_repeat('a', 256),
    'non-ascii' => 'কী-১২৩৪৫৬৭৮',
]);

it('accepts a UUID key', function (): void {
    $this->postJson(IDEM_URI, [], ['Idempotency-Key' => '0b9f0c8e-6a3b-4d6e-9a1f-2c3d4e5f6a7b'])->assertCreated();
});

it('returns request-in-progress while the same key is executing', function (): void {
    $key = 'key-0000-inflight';
    $request = Request::create(IDEM_URI, 'POST');
    $lock = Cache::lock(EnsureIdempotency::lockName(EnsureIdempotency::storageKey($request, $key)), 30);

    expect($lock->get())->toBeTrue();

    $this->postJson(IDEM_URI, ['qty' => 1], ['Idempotency-Key' => $key])
        ->assertStatus(409)
        ->assertHeader('Retry-After', '1')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/request-in-progress');

    expect($this->executions)->toBe(0);

    $lock->release();

    $this->postJson(IDEM_URI, ['qty' => 1], ['Idempotency-Key' => $key])->assertCreated();
    expect($this->executions)->toBe(1);
});

it('never stores a 5xx response', function (): void {
    $headers = ['Idempotency-Key' => 'key-0000-servererror'];

    $this->postJson('/api/v1/storefront/_test/idempotent-fails', [], $headers)->assertStatus(503);
    $this->postJson('/api/v1/storefront/_test/idempotent-fails', [], $headers)
        ->assertStatus(503)
        ->assertHeaderMissing('Idempotent-Replayed');

    expect($this->executions)->toBe(2);
});

it('does not store a 4xx, so the client can retry the same key', function (): void {
    $headers = ['Idempotency-Key' => 'key-0000-clienterror'];

    $this->postJson('/api/v1/storefront/_test/idempotent-rejects', [], $headers)->assertStatus(409);
    $this->postJson('/api/v1/storefront/_test/idempotent-rejects', [], $headers)
        ->assertStatus(409)
        ->assertHeaderMissing('Idempotent-Replayed');

    expect($this->executions)->toBe(2);
});

it('isolates keys per user', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $headers = ['Idempotency-Key' => 'key-0000-shared'];

    $this->actingAs($alice)->postJson(IDEM_URI, ['qty' => 1], $headers)->assertCreated()->assertJsonPath('execution', 1);
    $this->actingAs($bob)->postJson(IDEM_URI, ['qty' => 1], $headers)
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('execution', 2);
    $this->actingAs($alice)->postJson(IDEM_URI, ['qty' => 1], $headers)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('execution', 1);

    expect($this->executions)->toBe(2);
});

it('isolates the same key across routes', function (): void {
    $headers = ['Idempotency-Key' => 'key-0000-routes'];

    $this->postJson(IDEM_URI, [], $headers)->assertCreated();
    $this->postJson('/api/v1/storefront/_test/idempotent-rejects', [], $headers)->assertStatus(409);

    expect($this->executions)->toBe(2);
});

it('expires a stored response after the configured TTL', function (): void {
    config(['idempotency.ttl_seconds' => 60]);
    $headers = ['Idempotency-Key' => 'key-0000-ttl'];

    $this->postJson(IDEM_URI, [], $headers)->assertCreated();
    $this->travel(61)->seconds();
    $this->postJson(IDEM_URI, [], $headers)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect($this->executions)->toBe(2);
});
