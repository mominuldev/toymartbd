<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
| P10 launch checklist "404 and 500 pages styled" — the Laravel side. Web routes on the API host
| render the branded, bilingual resources/views/errors/* pages; everything under api/* stays an
| RFC 9457 problem+json whatever the client accepts (rule 18), maintenance mode included.
*/

beforeEach(function (): void {
    config(['app.debug' => false]);
    Route::get('/_test/web-boom', fn () => throw new RuntimeException('SQLSTATE secret-dsn password=hunter2'));
    Route::get('/_test/web-419', fn () => abort(419));
    Route::get('/_test/web-502', fn () => abort(502));
    Route::middleware('api')->get('/api/v1/storefront/_test/api-boom', fn () => throw new RuntimeException('SQLSTATE password=hunter2'));
});

const HTML = ['Accept' => 'text/html,application/xhtml+xml'];

it('renders a branded, bilingual 404 for an unknown web path', function (): void {
    $response = $this->get('/no-such-page', HTML);

    $response->assertNotFound()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Toy Mart BD')
        ->assertSee('This page isn&#039;t here', false)
        ->assertSee('এই পাতাটি নেই')
        ->assertSee('class="lang-bn"', false)
        ->assertSee('name="robots" content="noindex"', false)
        ->assertSee('--tm-color-bg-canvas', false) // the inlined design tokens
        ->assertSee('Reference / রেফারেন্স:', false); // the request id a customer can quote
});

it('renders a branded 500 that never shows the exception message', function (): void {
    $response = $this->get('/_test/web-boom', HTML);

    $response->assertStatus(500)
        ->assertSee('Something went wrong on our side')
        ->assertSee('আমাদের দিকে কিছু সমস্যা হয়েছে')
        ->assertDontSee('hunter2')
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('RuntimeException');
});

it('renders the branded maintenance page as 503 while the app is down', function (): void {
    $this->app->maintenanceMode()->activate([]);

    try {
        $this->get('/', HTML)
            ->assertStatus(503)
            ->assertSee('Back in a few minutes')
            ->assertSee('কিছুক্ষণের মধ্যেই ফিরছি');
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }
});

it('falls back to the generic 4xx and 5xx pages for other codes', function (): void {
    $this->get('/_test/web-419', HTML)->assertStatus(419)->assertSee('Error 419')->assertSee('অনুরোধটি সম্পন্ন করা যায়নি');
    $this->get('/_test/web-502', HTML)->assertStatus(502)->assertSee('Error 502')->assertSee('Something went wrong on our side');
});

it('keeps API errors as problem+json even for a browser, never the HTML page', function (string $path, int $status): void {
    $response = $this->get($path, HTML);

    $response->assertStatus($status)->assertHeader('Content-Type', 'application/problem+json');
    expect($response->getContent())->not->toContain('<html')
        ->and($response->getContent())->not->toContain('hunter2')
        ->and($response->json('status'))->toBe($status);
})->with([
    'unknown API route' => ['/api/v1/storefront/no-such-endpoint', 404],
    'unknown API version' => ['/api/v9/anything', 404],
    'server error' => ['/api/v1/storefront/_test/api-boom', 500],
]);

it('answers API clients with a 503 problem in maintenance mode, not the HTML page', function (): void {
    $this->app->maintenanceMode()->activate([]);

    try {
        $response = $this->get('/api/v1/health', HTML);
        $response->assertStatus(503)->assertHeader('Content-Type', 'application/problem+json');
        expect($response->getContent())->not->toContain('<html');
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }
});

it('uses design tokens only — no raw colour in the error views (rule 26)', function (): void {
    foreach (File::files(resource_path('views/errors')) as $file) {
        expect(preg_match('/#[0-9a-fA-F]{3,8}\b|rgb\(|hsl\(/', $file->getContents()))->toBe(0, $file->getFilename());
    }
});
