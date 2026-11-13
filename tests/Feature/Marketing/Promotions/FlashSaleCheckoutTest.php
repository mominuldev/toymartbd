<?php

declare(strict_types=1);

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Marketing\Exceptions\PromotionRejected;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\PromotionUsage;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Marketing\Promotions\PromoKit;

/*
 * P7 flash sales and promotion usage at checkout: per-variant sale price for a limited
 * allotment, claimed under lock inside the order transaction, given back on cancel;
 * per-phone limits across guest checkouts; storefront payloads; ISR at the boundaries.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
});

function flashPlace(string $cart, string $phone = CheckoutKit::PHONE): Order
{
    return app(PlaceOrder::class)->handle(CheckoutKit::data($cart, $phone));
}

it('sells only the allotment at the sale price; extra units stay at full price', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    PromoKit::flash([$v->id => ['750.00', 3]]);

    $b = PromoKit::price([$v->id => 5]);

    expect($b->promotionDiscount->toDecimal())->toBe('750.00') // 3 × 250
        ->and($b->merchandiseTotal()->toDecimal())->toBe('4250.00')
        ->and($b->promotions[0]->flashUnits)->toBe([$v->id => 3]);
    PromoKit::assertSums($b);
});

it('honours the per-order limit', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    PromoKit::flash([$v->id => ['800.00', 10, 1]]);

    expect(PromoKit::price([$v->id => 3])->promotionDiscount->toDecimal())->toBe('200.00');
});

it('claims the allotment at checkout and refuses an order once it has sold out', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    PromoKit::flash([$v->id => ['600.00', 2]]);

    $first = flashPlace(CheckoutKit::cart([$v->id => 2]));
    expect($first->promotion_discount->toDecimal())->toBe('800.00')
        ->and(FlashSaleItem::query()->sole()->claimed)->toBe(2)
        ->and(PromotionUsage::query()->sole()->flash_units)->toBe([(string) $v->id => 2]);

    // The cart re-prices at full price now (allotment gone), so a fresh checkout succeeds at 1000.
    $second = CheckoutKit::cart([$v->id => 1]);
    expect(PromoKit::price([$v->id => 1])->promotionDiscount->isZero())->toBeTrue();
    flashPlace($second, '01812345678');

    expect(FlashSaleItem::query()->sole()->claimed)->toBe(2);
    PromoKit::assertInvariants();
});

it('refuses with promotion-rejected when the allotment ran out between pricing and placing', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    $promotion = PromoKit::flash([$v->id => ['600.00', 1]]);
    $cart = CheckoutKit::cart([$v->id => 1]);
    $data = CheckoutKit::data($cart); // priced with the flash unit

    // Someone else takes the last unit.
    FlashSaleItem::query()->where('promotion_id', $promotion->id)->update(['claimed' => 1]);

    // The engine now sees no units; the expected total no longer matches → price-changed.
    expect(fn () => app(PlaceOrder::class)->handle($data))->toThrow(App\Domain\Ordering\Checkout\PriceChanged::class);
    expect(Order::query()->count())->toBe(0);
});

it('throws PromotionRejected when the claim under lock finds the allotment short', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    $promotion = PromoKit::flash([$v->id => ['600.00', 1]]);
    $breakdown = PromoKit::price([$v->id => 1]);
    FlashSaleItem::query()->where('promotion_id', $promotion->id)->update(['claimed' => 1]);

    expect(fn () => Illuminate\Support\Facades\DB::transaction(fn () => app(App\Domain\Marketing\Services\PromotionUsages::class)
        ->record($breakdown->promotions, 1, App\Support\PhoneNumber::parse(CheckoutKit::PHONE))))
        ->toThrow(PromotionRejected::class);
});

it('maps a promotion refusal at checkout to a 409 price-changed problem', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    $promotion = PromoKit::flash([$v->id => ['600.00', 1]]);
    $token = CartTestKit::storefrontToken();
    $cart = CheckoutKit::cart([$v->id => 1]);
    $payload = CheckoutKit::payload($cart);

    // A concurrent order takes the last unit between re-pricing and the claim under lock.
    Order::creating(function () use ($promotion): void {
        FlashSaleItem::query()->where('promotion_id', $promotion->id)->update(['claimed' => 1]);
    });

    $this->withToken($token)->postJson('/api/v1/storefront/checkout', $payload, [CartTestKit::HEADER => $cart, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/price-changed')
        ->assertJsonPath('errors.0.message', 'The flash-sale allotment for this item has just sold out. Please check the updated total.');
    expect(Order::query()->count())->toBe(0);
});

it('gives the flash units, usage count and budget back when the order is cancelled', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    $promotion = PromoKit::flash([$v->id => ['700.00', 5]], ['budget' => '5000.00']);

    $order = flashPlace(CheckoutKit::cart([$v->id => 2]));
    expect($promotion->refresh()->usage_count)->toBe(1)
        ->and($promotion->discount_spent->toDecimal())->toBe('600.00')
        ->and(FlashSaleItem::query()->sole()->claimed)->toBe(2);

    app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest);

    expect($promotion->refresh()->usage_count)->toBe(0)
        ->and($promotion->discount_spent->toDecimal())->toBe('0.00')
        ->and(FlashSaleItem::query()->sole()->claimed)->toBe(0)
        ->and(PromotionUsage::query()->count())->toBe(0);
    PromoKit::assertInvariants();
});

it('also reverses the coupon usage on cancel (OrderCancelled contract)', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    $coupon = App\Domain\Marketing\Models\Coupon::factory()->fixed('100.00')->create(['code' => 'BACK100']);
    $order = flashPlace(CheckoutKit::cart([$v->id => 1], 'BACK100'));
    expect($coupon->refresh()->used_count)->toBe(1);

    app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest);

    expect($coupon->refresh()->used_count)->toBe(0);
});

it('enforces the per-phone limit across guest checkouts, however the phone is typed', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    $promotion = PromoKit::percentOff('10', ['usage_limit_per_customer' => 1]);

    $first = flashPlace(CheckoutKit::cart([$v->id => 1]), '01712345678');
    expect($first->promotion_discount->toDecimal())->toBe('100.00');

    // Same phone, another guest cart, another spelling: no discount any more.
    expect(PromoKit::price([$v->id => 1], phone: '+880 1712-345678')->promotionDiscount->isZero())->toBeTrue();
    $second = flashPlace(CheckoutKit::cart([$v->id => 1]), '+8801712345678');
    expect($second->promotion_discount->isZero())->toBeTrue();

    // Another phone still gets it.
    $third = flashPlace(CheckoutKit::cart([$v->id => 1]), '01812345678');
    expect($third->promotion_discount->toDecimal())->toBe('100.00')
        ->and($promotion->refresh()->usage_count)->toBe(2);
    PromoKit::assertInvariants();
});

it('shows the flash offer on cards, the product page and the flash-sale endpoint', function (): void {
    $v = CartTestKit::variant('1000.00', 20);
    $promotion = PromoKit::flash([$v->id => ['850.00', 4]]);
    $token = CartTestKit::storefrontToken();
    $slug = $v->product->slug;

    $card = collect($this->withToken($token)->getJson('/api/v1/storefront/products')->assertOk()->json('data'))->firstWhere('slug', $slug);
    expect($card['flash_sale'])->toMatchArray([
        'promotion_id' => $promotion->id, 'sale_price' => '850.00', 'regular_price' => '1000.00', 'discount_percent' => 15, 'remaining' => 4,
        'ends_at' => $promotion->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
    ]);

    $pdp = $this->withToken($token)->getJson("/api/v1/storefront/products/{$slug}")->assertOk();
    $pdp->assertJsonPath('flash_sale.sale_price', '850.00')->assertJsonPath("flash_sale.variants.{$v->id}.sale_price", '850.00');

    $sales = $this->withToken($token)->getJson('/api/v1/storefront/flash-sales')->assertOk();
    $sales->assertJsonPath('sales.0.id', $promotion->id)
        ->assertJsonPath('sales.0.name_bn', 'ফ্ল্যাশ সেল')
        ->assertJsonPath('sales.0.products.0.flash_sale.sale_price', '850.00');

    // Sold out → no badge.
    FlashSaleItem::query()->update(['claimed' => 4]);
    app()->forgetScopedInstances();
    $card = collect($this->withToken($token)->getJson('/api/v1/storefront/products')->json('data'))->firstWhere('slug', $slug);
    expect($card['flash_sale'])->toBeNull();
});

it('revalidates the storefront when a promotion starts or ends', function (): void {
    Bus::fake([RevalidateStorefrontTags::class]);
    $v = CartTestKit::variant('1000.00', 5);
    PromoKit::flash([$v->id => ['900.00', 2]], ['starts_at' => now()->addMinute(), 'ends_at' => now()->addMinutes(3)]);
    Illuminate\Support\Facades\Cache::forever(App\Console\Commands\Marketing\AnnouncePromotionBoundaries::CACHE_KEY, now()->toIso8601String());

    $this->travel(2)->minutes();
    $this->artisan('toymart:promotions:boundaries')->assertSuccessful();
    Bus::assertDispatched(RevalidateStorefrontTags::class, fn ($job) => in_array('flash-sale', $job->tags, true) && in_array('product:'.$v->product->slug, $job->tags, true));

    $this->artisan('toymart:promotions:boundaries')->expectsOutputToContain('Revalidated 0')->assertSuccessful();
    $this->travel(2)->minutes();
    $this->artisan('toymart:promotions:boundaries')->expectsOutputToContain('Revalidated 1')->assertSuccessful();
});
