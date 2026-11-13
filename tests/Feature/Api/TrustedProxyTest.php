<?php

declare(strict_types=1);

use App\Providers\ApiServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Every storefront request arrives from the Next.js server. Laravel takes the shopper's IP from
// X-Forwarded-For only when the TCP peer is in TRUSTED_PROXIES (config/trustedproxy.php), so
// per-IP rate limits mean something without letting any client pick its own bucket.

beforeEach(function (): void {
    Route::middleware('api')->get('api/v1/storefront/_test/ip', fn (Request $request) => ['ip' => $request->ip()]);
});

it('ignores X-Forwarded-For by default (no trusted proxies)', function (): void {
    config(['trustedproxy.proxies' => []]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->getJson('/api/v1/storefront/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()->assertJsonPath('ip', '10.0.0.5');
});

it('ignores a spoofed X-Forwarded-For from an address that is not a trusted proxy', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->getJson('/api/v1/storefront/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()->assertJsonPath('ip', '198.51.100.7');
});

it('uses the forwarded shopper IP from a trusted proxy (IP or CIDR)', function (string $proxies): void {
    config(['trustedproxy.proxies' => explode(',', $proxies)]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->getJson('/api/v1/storefront/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()->assertJsonPath('ip', '203.0.113.9');
})->with(['exact ip' => '10.0.0.5', 'cidr' => '10.0.0.0/24', 'list' => '192.0.2.1,10.0.0.5']);

it('does not let a client prepend its own address in front of the trusted chain', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);

    // The client sent "1.2.3.4"; the Next server appended the real peer it saw.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->getJson('/api/v1/storefront/_test/ip', ['X-Forwarded-For' => '1.2.3.4, 203.0.113.9'])
        ->assertOk()->assertJsonPath('ip', '203.0.113.9');
});

it('does not trust X-Forwarded-Host even from a trusted proxy', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);
    Route::middleware('api')->get('api/v1/storefront/_test/host', fn (Request $request) => ['host' => $request->getHost()]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->getJson('/api/v1/storefront/_test/host', ['X-Forwarded-Host' => 'evil.example'])
        ->assertOk()->assertJsonPath('host', 'localhost');
});

it('parses TRUSTED_PROXIES and refuses wildcards', function (): void {
    $parse = fn (string $value): array => App\Support\TrustedProxies::parse($value);

    expect($parse(''))->toBe([])
        ->and($parse('*'))->toBe([])
        ->and($parse('**'))->toBe([])
        ->and($parse(' 10.0.0.5 , 10.0.1.0/24,*'))->toBe(['10.0.0.5', '10.0.1.0/24']);
});

it('keys the OTP per-IP limit on the forwarded shopper IP behind a trusted proxy', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);
    Route::middleware('api')->post('api/v1/storefront/_test/otp-ip', fn () => ['ok' => true])->middleware('throttle:otp-ip');

    $from = fn (string $shopper) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->postJson('/api/v1/storefront/_test/otp-ip', [], ['X-Forwarded-For' => $shopper]);

    for ($i = 1; $i <= ApiServiceProvider::OTP_PER_IP_PER_HOUR; $i++) {
        $from('203.0.113.9')->assertOk();
    }
    $from('203.0.113.9')->assertStatus(429);
    // Another shopper behind the same Next server has their own bucket.
    $from('203.0.113.10')->assertOk();
});

it('caps OTP requests per IP at a sane hourly value', function (): void {
    expect(ApiServiceProvider::OTP_PER_IP_PER_HOUR)->toBeLessThanOrEqual(50)->toBeGreaterThan(ApiServiceProvider::OTP_PER_TEN_MINUTES);
});
