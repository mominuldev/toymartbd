<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;

/*
 * Response security headers (P9 security review, docs/audits/SECURITY-REVIEW.md §Headers).
 */

it('sends the baseline headers on the JSON API with a CSP that loads nothing', function (): void {
    $this->getJson('/api/v1/storefront/ping')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends the locked-down CSP on API problems too (a 401 is still a page a browser can open)', function (): void {
    $this->getJson('/api/v1/admin/orders')
        ->assertUnauthorized()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
});

it('sends HSTS only over HTTPS', function (): void {
    $this->get('https://localhost/api/v1/storefront/ping')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('serves the admin shell with a nonce CSP, and nonces its one inline script', function (): void {
    config(['services.storefront.url' => 'https://shop.toymartbd.test/en']);

    $response = $this->get('/admin/orders')->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $csp, $m);
    $nonce = $m[1] ?? '';

    expect($nonce)->not->toBe('')
        ->and($csp)->toContain("script-src 'self' 'nonce-{$nonce}'")
        ->and($csp)->not->toContain("'unsafe-eval'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain('frame-src \'self\' https://shop.toymartbd.test;')
        ->and($response->getContent())->toContain('<script nonce="'.$nonce.'">');

    // The nonce is per request.
    expect((string) $this->get('/admin')->headers->get('Content-Security-Policy'))->not->toContain($nonce);
});

it('never lets the script directive fall back to unsafe-inline', function (): void {
    expect(SecurityHeaders::adminPolicy('abc'))
        ->toMatch("/script-src 'self' 'nonce-abc'(;| )/")
        ->not->toMatch("/script-src[^;]*'unsafe-inline'/");
});

it('keeps a header a response already set', function (): void {
    Illuminate\Support\Facades\Route::get('/_test/own-frame-policy', fn () => response('ok')->header('X-Frame-Options', 'SAMEORIGIN'));

    $this->get('/_test/own-frame-policy')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('allows no cross-origin reads unless CORS_ALLOWED_ORIGINS lists the origin', function (): void {
    $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/storefront/ping')
        ->assertHeaderMissing('Access-Control-Allow-Origin');

    $this->call('OPTIONS', '/api/v1/storefront/ping', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ])->assertHeaderMissing('Access-Control-Allow-Origin');

    expect(config('cors.supports_credentials'))->toBeFalse();
});

it('marks the session cookie secure by default in production', function (): void {
    $previous = [$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null, getenv('APP_ENV')];
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
    putenv('APP_ENV=production');

    try {
        $production = (require config_path('session.php'))['secure'];
    } finally {
        [$_ENV['APP_ENV'], $_SERVER['APP_ENV']] = [$previous[0], $previous[1]];
        putenv('APP_ENV='.($previous[2] === false ? '' : $previous[2]));
    }

    expect($production)->toBeTrue()
        ->and((require config_path('session.php'))['secure'])->toBeFalse();
});

it('does not expose the private disk over /storage (no signed serve or upload route)', function (): void {
    expect(Illuminate\Support\Facades\Route::has('storage.local'))->toBeFalse()
        ->and(Illuminate\Support\Facades\Route::has('storage.local.upload'))->toBeFalse();
});
