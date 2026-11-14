<?php

declare(strict_types=1);

use App\Http\Problems\ProblemType;
use App\Providers\ApiServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * Rate-limit inventory (P9 security review, docs/audits/SECURITY-REVIEW.md §Rate limits).
 *
 * Every abuse-prone endpoint must carry its named limiter. Behaviour of the older limiters
 * (login, OTP, checkout, order tracking, storefront/admin groups) is tested in
 * tests/Feature/Api/RateLimitTest.php, OtpAuthTest and OrderLookupApiTest; the limiters added
 * by the review are exercised here.
 */

dataset('throttled routes', [
    'admin sign-in' => ['api.v1.admin.auth.login', 'throttle:login'],
    'OTP send (per phone)' => ['api.v1.storefront.auth.otp.request', 'throttle:otp'],
    'OTP send (per IP)' => ['api.v1.storefront.auth.otp.request', 'throttle:otp-ip'],
    'OTP verify' => ['api.v1.storefront.auth.otp.verify', 'throttle:otp-verify'],
    'order tracking' => ['api.v1.storefront.orders.track', 'throttle:order-track'],
    'order confirmation lookup' => ['api.v1.storefront.orders.show', 'throttle:order-lookup'],
    'checkout' => ['api.v1.storefront.checkout.store', 'throttle:checkout'],
    'payment start' => ['api.v1.storefront.orders.payment.store', 'throttle:checkout'],
    'payment return' => ['api.v1.storefront.payments.sslcommerz.return', 'throttle:checkout'],
    'coupon apply' => ['api.v1.storefront.cart.coupon.store', 'throttle:coupon'],
    'cart contact (recovery SMS target)' => ['api.v1.storefront.cart.contact.update', 'throttle:cart-contact'],
    'cart recovery restore' => ['api.v1.storefront.cart.restore', 'throttle:order-track'],
    'review submit' => ['api.v1.storefront.account.reviews.store', 'throttle:reviews'],
    'search suggest' => ['api.v1.storefront.search.suggest', 'throttle:search-suggest'],
    'tracking relay' => ['api.v1.storefront.tracking.events.store', 'throttle:tracking-events'],
    'storefront group' => ['api.v1.storefront.ping', 'throttle:storefront'],
    'admin group' => ['api.v1.admin.orders.index', 'throttle:admin'],
]);

it('puts the named limiter on every abuse-prone endpoint', function (string $name, string $middleware): void {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull("route {$name} is missing");
    expect($route->gatherMiddleware())->toContain($middleware);
})->with('throttled routes');

function assertRateLimited(TestResponse $response): void
{
    $response->assertStatus(429)
        ->assertJsonPath('type', ProblemType::TooManyRequests->uri());

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
}

beforeEach(function (): void {
    Route::middleware('api')->prefix('api/v1/storefront/_sec')->group(function (): void {
        Route::post('/login', fn () => ['ok' => true])->middleware('throttle:login');
        Route::post('/coupon', fn () => ['ok' => true])->middleware('throttle:coupon');
        Route::put('/contact', fn () => ['ok' => true])->middleware('throttle:cart-contact');
    });
});

it('limits admin sign-in per account across IPs, so a botnet cannot spread guesses', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::LOGIN_PER_ACCOUNT_PER_FIFTEEN_MINUTES; $i++) {
        // A fresh IP every time stays under the per-IP limit.
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])
            ->postJson('/api/v1/storefront/_sec/login', ['email' => 'Owner@ToyMartBD.test'])->assertOk();
    }

    assertRateLimited($this->withServerVariables(['REMOTE_ADDR' => '10.1.0.200'])
        ->postJson('/api/v1/storefront/_sec/login', ['email' => ' owner@toymartbd.test ']));

    // Another account is unaffected.
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.201'])
        ->postJson('/api/v1/storefront/_sec/login', ['email' => 'manager@toymartbd.test'])->assertOk();
});

it('limits coupon attempts per cart, then per IP across carts', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::COUPON_PER_CART_PER_TEN_MINUTES; $i++) {
        $this->postJson('/api/v1/storefront/_sec/coupon', ['code' => "GUESS{$i}"], ['X-Cart-Token' => 'cart-a'])->assertOk();
    }
    assertRateLimited($this->postJson('/api/v1/storefront/_sec/coupon', ['code' => 'GUESS'], ['X-Cart-Token' => 'cart-a']));

    // A new cart from the same IP gets its own per-cart budget, but the IP budget still caps the total.
    // A throttled attempt is refused before the IP limit counts it.
    $sent = ApiServiceProvider::COUPON_PER_CART_PER_TEN_MINUTES;
    $cart = 0;
    while ($sent < ApiServiceProvider::COUPON_PER_IP_PER_TEN_MINUTES) {
        $cart++;
        for ($i = 0; $i < ApiServiceProvider::COUPON_PER_CART_PER_TEN_MINUTES && $sent < ApiServiceProvider::COUPON_PER_IP_PER_TEN_MINUTES; $i++, $sent++) {
            $this->postJson('/api/v1/storefront/_sec/coupon', ['code' => 'X'], ['X-Cart-Token' => "cart-{$cart}"]);
        }
    }

    assertRateLimited($this->postJson('/api/v1/storefront/_sec/coupon', ['code' => 'X'], ['X-Cart-Token' => 'cart-fresh']));
    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
        ->postJson('/api/v1/storefront/_sec/coupon', ['code' => 'X'], ['X-Cart-Token' => 'cart-fresh'])->assertOk();
});

it('limits cart contact captures per IP (each one is a future recovery SMS)', function (): void {
    for ($i = 1; $i <= ApiServiceProvider::CART_CONTACT_PER_IP_PER_HOUR; $i++) {
        $this->putJson('/api/v1/storefront/_sec/contact', [], ['X-Cart-Token' => "cart-{$i}"])->assertOk();
    }

    assertRateLimited($this->putJson('/api/v1/storefront/_sec/contact', [], ['X-Cart-Token' => 'cart-new']));
});

it('gives the whole-shop server budget only to GET reads from a trusted proxy, never to a write', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);
    Route::middleware(['api', 'throttle:storefront'])->post('/api/v1/storefront/_sec/write', fn () => ['ok' => true]);

    for ($i = 1; $i <= ApiServiceProvider::STOREFRONT_PER_MINUTE; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->postJson('/api/v1/storefront/_sec/write')->assertOk();
    }

    assertRateLimited($this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->postJson('/api/v1/storefront/_sec/write'));
    // The proxy's cached GET reads keep their own budget.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson('/api/v1/storefront/ping')->assertOk();
});

it('never lets a direct client claim the server budget, with or without a forged X-Forwarded-For', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.5']]);

    for ($i = 1; $i <= ApiServiceProvider::STOREFRONT_PER_MINUTE; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/api/v1/storefront/ping', ['X-Forwarded-For' => "203.0.113.{$i}"])->assertOk();
    }

    // The forged header is ignored from an untrusted peer: still the attacker's own 120/min bucket.
    assertRateLimited($this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson('/api/v1/storefront/ping'));
});
