<?php

declare(strict_types=1);

use App\Http\Problems\ProblemType;
use App\Models\User;
use App\Providers\ApiServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Route::middleware('api')->prefix('api/v1/storefront/_test')->group(function (): void {
        Route::post('/login', fn () => ['ok' => true])->middleware('throttle:login');
        Route::post('/otp', fn () => ['ok' => true])->middleware('throttle:otp');
        Route::post('/checkout', fn () => ['ok' => true])->middleware('throttle:checkout');
    });
});

function assertThrottled(TestResponse $response): void
{
    $response->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', ProblemType::TooManyRequests->uri())
        ->assertJsonPath('status', 429);

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
}

it('allows 120 storefront requests a minute per IP, then returns 429 with Retry-After', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::STOREFRONT_PER_MINUTE; $i++) {
        $this->getJson('/api/v1/storefront/ping')->assertOk();
    }

    assertThrottled($this->getJson('/api/v1/storefront/ping'));

    // A different IP has its own bucket.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson('/api/v1/storefront/ping')->assertOk();
});

it('limits login to 5 a minute per IP and phone', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::LOGIN_PER_MINUTE; $i++) {
        $this->postJson('/api/v1/storefront/_test/login', ['phone' => '01712345678'])->assertOk();
    }

    assertThrottled($this->postJson('/api/v1/storefront/_test/login', ['phone' => '+8801712345678']));
    $this->postJson('/api/v1/storefront/_test/login', ['phone' => '01812345678'])->assertOk();
});

it('limits OTP to 3 per 10 minutes per phone, across IPs', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::OTP_PER_TEN_MINUTES; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$i}"])
            ->postJson('/api/v1/storefront/_test/otp', ['phone' => '01712345678'])->assertOk();
    }

    $response = $this->postJson('/api/v1/storefront/_test/otp', ['phone' => '01712345678']);
    assertThrottled($response);
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(60);

    $this->postJson('/api/v1/storefront/_test/otp', ['phone' => '01912345678'])->assertOk();
});

it('limits checkout to 10 a minute per cart', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::CHECKOUT_PER_MINUTE; $i++) {
        $this->postJson('/api/v1/storefront/_test/checkout', [], ['X-Cart-Token' => 'cart-a'])->assertOk();
    }

    assertThrottled($this->postJson('/api/v1/storefront/_test/checkout', [], ['X-Cart-Token' => 'cart-a']));
    $this->postJson('/api/v1/storefront/_test/checkout', [], ['X-Cart-Token' => 'cart-b'])->assertOk();
});

it('limits admin to 300 a minute, keyed by user once signed in', function (): void {
    $limiter = RateLimiter::limiter('admin');
    expect($limiter)->not->toBeNull();

    $guest = Request::create('/api/v1/admin/ping', server: ['REMOTE_ADDR' => '10.0.0.9']);
    $signedIn = Request::create('/api/v1/admin/ping', server: ['REMOTE_ADDR' => '10.0.0.9']);
    $user = new User;
    $user->id = 42;
    $signedIn->setUserResolver(fn (): User => $user);

    /** @var Limit $guestLimit */
    $guestLimit = $limiter($guest);
    /** @var Limit $userLimit */
    $userLimit = $limiter($signedIn);

    expect($guestLimit->maxAttempts)->toBe(300)
        ->and($guestLimit->decaySeconds)->toBe(60)
        ->and($guestLimit->key)->toBe('ip:10.0.0.9')
        ->and($userLimit->key)->toBe('user:42');
});

it('returns 429 on the admin group at its threshold', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::ADMIN_PER_MINUTE; $i++) {
        $this->getJson('/api/v1/admin/ping');
    }

    assertThrottled($this->getJson('/api/v1/admin/ping'));
});

it('never throttles webhooks', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::STOREFRONT_PER_MINUTE + 5; $i++) {
        $this->getJson('/api/v1/webhooks/ping')->assertOk();
    }
});
