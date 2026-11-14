<?php

declare(strict_types=1);

use App\Console\Commands\Monitoring\SyntheticCartCommand;
use App\Domain\Cart\Models\Cart;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Ordering\Models\Order;
use App\Models\SystemHeartbeat;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Cart\CartTestKit;

/*
 * The synthetic add-to-cart check (§11): the command's real HTTP requests are routed into this
 * application, so the whole public storefront flow runs — and nothing is ordered or moved.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config([
        'monitoring.synthetic.api_url' => 'https://api.synthetic.test',
        'monitoring.synthetic.token' => CartTestKit::storefrontToken(),
    ]);
    $this->seen = [];

    // Forward every outgoing request to the app under test.
    Http::fake(function (HttpRequest $request) {
        $this->seen[] = $request;
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $uri = (string) preg_replace('#^https://api\.synthetic\.test#', '', $request->url());
        $response = $this->withHeaders($headers)->json($request->method(), $uri, $request->method() === 'GET' ? [] : $request->data());

        return Http::response((string) $response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json']);
    });
});

it('lists, reads, adds to a throwaway cart, reads it and empties it — creating no order and moving no stock', function (): void {
    CartTestKit::variant('1250.00', 5);
    $movements = StockMovement::query()->count();

    $this->artisan('toymart:synthetic:cart')->assertSuccessful()->expectsOutputToContain('Synthetic add-to-cart passed');

    expect(collect($this->seen)->map(fn (HttpRequest $r): string => $r->method().' '.parse_url($r->url(), PHP_URL_PATH))->all())->toBe([
        'GET /api/v1/storefront/products',
        'GET /api/v1/storefront/products/'.App\Domain\Catalog\Models\Product::query()->value('slug'),
        'POST /api/v1/storefront/cart/items',
        'GET /api/v1/storefront/cart',
        'DELETE '.parse_url($this->seen[4]->url(), PHP_URL_PATH),
    ])->and($this->seen[4]->method())->toBe('DELETE')
        ->and(parse_url($this->seen[4]->url(), PHP_URL_PATH))->toMatch('#^/api/v1/storefront/cart/items/\d+$#');
    foreach ($this->seen as $request) {
        expect($request->header('User-Agent')[0])->toBe(SyntheticCartCommand::USER_AGENT)
            ->and($request->header('X-Synthetic-Check')[0])->toBe('1')
            ->and($request->header('X-Request-Id')[0])->toStartWith('synthetic-');
    }

    expect(Order::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe($movements)
        ->and(Cart::query()->sole()->items()->count())->toBe(0)
        ->and(SystemHeartbeat::lastSuccess(SystemHeartbeat::SYNTHETIC_CART))->not->toBeNull();
});

it('uses the configured product when one is set', function (): void {
    $variant = CartTestKit::variant('900.00', 3);
    config(['monitoring.synthetic.product_slug' => $variant->product->slug]);

    $this->artisan('toymart:synthetic:cart')->assertSuccessful();

    expect(parse_url($this->seen[0]->url(), PHP_URL_PATH))->toBe('/api/v1/storefront/products/'.$variant->product->slug);
});

it('fails, records the failing step and never logs the token when nothing is in stock', function (): void {
    $this->artisan('toymart:synthetic:cart')->assertFailed()->expectsOutputToContain('list products: no in-stock product listed');

    $row = SystemHeartbeat::query()->findOrFail(SystemHeartbeat::SYNTHETIC_CART);
    expect($row->last_failure_at)->not->toBeNull()
        ->and($row->last_success_at)->toBeNull()
        ->and($row->last_error)->not->toContain((string) config('monitoring.synthetic.token'));
});

it('fails when the API rejects the token', function (): void {
    CartTestKit::variant('1250.00', 5);
    config(['monitoring.synthetic.token' => '1|not-a-real-token']);

    $this->artisan('toymart:synthetic:cart')->assertFailed()->expectsOutputToContain('HTTP 401');
});

it('is skipped, not failed, until a token is configured', function (): void {
    config(['monitoring.synthetic.token' => null]);

    $this->artisan('toymart:synthetic:cart')->assertSuccessful()->expectsOutputToContain('skipped');
    expect($this->seen)->toBe([]);
});
