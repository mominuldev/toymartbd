<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Models\Product;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Storefront\Recommendations\RecoKit;

/*
 * P10: GET /api/v1/storefront/cart/recommendations (placement cart_cross_sell, ≤ 8, never upsell)
 * and GET /api/v1/storefront/orders/{number}/recommendations (confirmation_cross_sell, ≤ 4, the
 * confirmation page's own access check) — docs/api/recommendations.md.
 */

beforeEach(function (): void {
    $this->storefront = CartTestKit::storefrontToken();
});

function cartReco(?string $cart): TestResponse
{
    return test()->withToken(test()->storefront)->getJson('/api/v1/storefront/cart/recommendations', $cart === null ? [] : [CartTestKit::HEADER => $cart]);
}

function orderReco(string $number, ?string $cart): TestResponse
{
    return test()->withToken(test()->storefront)->getJson('/api/v1/storefront/orders/'.$number.'/recommendations', $cart === null ? [] : [CartTestKit::HEADER => $cart]);
}

/** @return list<int> */
function railIds(TestResponse $response): array
{
    return array_map(static fn (array $card): int => $card['id'], $response->json('data'));
}

it('rails the cart lines\' cross-sells, never an upsell, never a cart product, in stock only', function (): void {
    $car = RecoKit::product('1500.00');
    $doll = RecoKit::product('800.00');
    $batteries = RecoKit::product('120.00');
    $soldOut = RecoKit::product('90.00', 0);
    $bag = RecoKit::product('300.00');
    $upgrade = RecoKit::product('3000.00');

    RecoKit::relate($car, $soldOut, RelationType::CrossSell, 0);
    RecoKit::relate($car, $batteries, RelationType::CrossSell, 1);
    RecoKit::relate($car, $doll, RelationType::CrossSell, 2); // already in the cart
    RecoKit::relate($doll, $bag, RelationType::CrossSell, 0);
    RecoKit::relate($car, $upgrade, RelationType::Upsell, 0);
    RecoKit::relate($car, $car, RelationType::CrossSell, 3);

    $cart = CheckoutKit::cart([$car->variants()->value('id') => 1, $doll->variants()->value('id') => 1]);

    $response = cartReco($cart)->assertOk()->assertJsonPath('placement', 'cart_cross_sell');

    // Round-robin by position across lines: the doll's first pick (bag, position 0) comes before the car's second.
    expect(railIds($response))->toBe([$bag->id, $batteries->id])
        ->and($response->json('data.0.in_stock'))->toBeTrue();
});

it('fills the cart rail from co-purchase and caps it at 8', function (): void {
    $car = RecoKit::product('1500.00');
    $curated = RecoKit::product('100.00');
    RecoKit::relate($car, $curated, RelationType::CrossSell);
    $bought = [];
    for ($i = 0; $i < 9; $i++) {
        RecoKit::copurchase($car, $bought[] = RecoKit::product('200.00'), 20 - $i);
    }
    RecoKit::copurchase($car, RecoKit::product('200.00'), 2); // below catalog.fbt_min_orders

    $ids = railIds(cartReco(CheckoutKit::cart([$car->variants()->value('id') => 1]))->assertOk());

    expect($ids)->toHaveCount(8)
        ->and($ids)->toBe([$curated->id, ...array_map(fn (Product $p): int => $p->id, array_slice($bought, 0, 7))]);
});

it('returns an empty rail for an empty cart and 404 for an unknown one', function (): void {
    $car = RecoKit::product();
    RecoKit::relate($car, RecoKit::product(), RelationType::CrossSell);
    $cart = CheckoutKit::cart([$car->variants()->value('id') => 1]);
    DB::table('cart_items')->delete();

    cartReco($cart)->assertOk()->assertExactJson(['data' => [], 'placement' => 'cart_cross_sell']);
    cartReco((string) Str::uuid())->assertNotFound()->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');
    cartReco(null)->assertNotFound();
});

describe('order confirmation', function (): void {
    beforeEach(function (): void {
        $this->seed([GeographySeeder::class, DeliverySeeder::class]);
        config(['risk.first_order_hold_threshold' => '100000.00']);

        $this->bought = RecoKit::product('1000.00');
        $this->cart = CheckoutKit::cart([$this->bought->variants()->value('id') => 1]);
        $this->number = $this->withToken($this->storefront)
            ->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($this->cart), [
                CartTestKit::HEADER => $this->cart, 'Idempotency-Key' => (string) Str::uuid(),
            ])->assertCreated()->json('number');
    });

    it('rails up to 4 cross-sells of the order, never an ordered product', function (): void {
        $picks = [];
        for ($i = 0; $i < 6; $i++) {
            RecoKit::relate($this->bought, $picks[] = RecoKit::product('150.00'), RelationType::CrossSell, $i);
        }
        RecoKit::relate($this->bought, $this->bought, RelationType::CrossSell, 0);
        RecoKit::relate($this->bought, RecoKit::product('5000.00'), RelationType::Upsell, 0);

        $response = orderReco($this->number, $this->cart)->assertOk()->assertJsonPath('placement', 'confirmation_cross_sell');

        expect(railIds($response))->toBe(array_map(fn (Product $p): int => $p->id, array_slice($picks, 0, 4)));
    });

    it('fails exactly like the confirmation page for a wrong token, no token, an unknown number or an old order', function (): void {
        RecoKit::relate($this->bought, RecoKit::product(), RelationType::CrossSell);
        $notFound = fn (TestResponse $r) => $r->assertNotFound()->assertJsonPath('type', 'https://toymartbd.com/problems/not-found');

        $notFound(orderReco($this->number, (string) Str::uuid()));
        $notFound(orderReco($this->number, null));
        $notFound(orderReco('TMB-26-99999', $this->cart));
        $notFound(orderReco($this->number, CheckoutKit::cart([RecoKit::product()->variants()->value('id') => 1])));

        Order::query()->where('number', $this->number)->update(['placed_at' => now()->subDays(31)]);
        $notFound(orderReco($this->number, $this->cart));
    });
});

it('needs the storefront token', function (): void {
    $this->getJson('/api/v1/storefront/cart/recommendations')->assertUnauthorized();
    $this->getJson('/api/v1/storefront/orders/TMB-26-00001/recommendations')->assertUnauthorized();
});
