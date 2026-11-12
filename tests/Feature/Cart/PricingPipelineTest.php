<?php

declare(strict_types=1);

use App\Domain\Cart\DTOs\PriceBreakdown;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Services\PricingPipeline;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Customer\Models\District;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Tests\Feature\Cart\CartTestKit;

/*
 * P4-CRT-02 — base → promotion (priority, stacking) → coupon → shipping → total.
 */

function priceLines(array $lines, ?string $coupon = null, ?int $districtId = null): PriceBreakdown
{
    return app(PricingPipeline::class)->price(new PricingInput(
        lines: $lines,
        couponCode: $coupon,
        district: $districtId === null ? null : District::query()->findOrFail($districtId),
    ));
}

function assertBreakdownSums(PriceBreakdown $b): void
{
    $rows = array_reduce($b->itemised(), fn (Money $c, array $r): Money => $c->plus($r['amount']), Money::zero());
    $lines = array_reduce($b->lines, fn (Money $c, $l): Money => $c->plus($l->total), Money::zero());

    expect($rows->toDecimal())->toBe($b->grandTotal->toDecimal())
        ->and($lines->toDecimal())->toBe($b->merchandiseTotal()->toDecimal());
}

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
});

it('prices the base subtotal with no promotions, coupon or destination', function (): void {
    $a = CartTestKit::variant('1250.00');
    $b = CartTestKit::variant('499.50');

    $result = priceLines([$a->id => 2, $b->id => 1]);

    expect($result->subtotal->toDecimal())->toBe('2999.50')
        ->and($result->discountTotal()->toDecimal())->toBe('0.00')
        ->and($result->shippingCharge)->toBeNull()
        ->and($result->grandTotal->toDecimal())->toBe('2999.50')
        ->and($result->itemCount())->toBe(3)
        ->and($result->lines[0]->variant->product->name_bn)->toBe('রিমোট কন্ট্রোল গাড়ি');
    assertBreakdownSums($result);
});

it('applies stackable promotions in priority order, each on what the previous left', function (): void {
    $v = CartTestKit::variant('1000.00');
    $low = Promotion::factory()->create(['name' => 'Fifty off', 'priority' => 5, 'rules' => ['type' => 'fixed', 'value' => '50']]);
    $high = Promotion::factory()->create(['name' => 'Ten percent', 'name_bn' => 'দশ শতাংশ ছাড়', 'priority' => 10, 'rules' => ['type' => 'percent', 'value' => '10']]);

    $result = priceLines([$v->id => 2]);

    // 10% of 2000 = 200 first, then ৳50 × 2 = 100 → 1700 (the other order would give 1710).
    expect(array_map(fn ($d) => $d->id, $result->promotions))->toBe([$high->id, $low->id])
        ->and($result->promotions[0]->amount->toDecimal())->toBe('200.00')
        ->and($result->promotions[0]->labelBn)->toBe('দশ শতাংশ ছাড়')
        ->and($result->promotions[1]->amount->toDecimal())->toBe('100.00')
        ->and($result->grandTotal->toDecimal())->toBe('1700.00');
    assertBreakdownSums($result);
});

it('stops the chain at the first non-stackable promotion that applies', function (): void {
    $v = CartTestKit::variant('1000.00');
    $stopper = Promotion::factory()->nonStackable()->create(['priority' => 10, 'rules' => ['type' => 'percent', 'value' => '10']]);
    Promotion::factory()->create(['priority' => 5, 'rules' => ['type' => 'percent', 'value' => '20']]);

    $result = priceLines([$v->id => 1]);

    expect($result->promotions)->toHaveCount(1)
        ->and($result->promotions[0]->id)->toBe($stopper->id)
        ->and($result->grandTotal->toDecimal())->toBe('900.00');
});

it('does not let a non-stackable promotion that matches nothing stop the chain', function (): void {
    $v = CartTestKit::variant('1000.00');
    $otherBrand = Brand::factory()->create();
    Promotion::factory()->nonStackable()->create(['priority' => 10, 'rules' => [
        'scope' => ['applies_to' => 'brands', 'ids' => [$otherBrand->id]], 'type' => 'percent', 'value' => '50',
    ]]);
    Promotion::factory()->create(['priority' => 5, 'rules' => ['type' => 'percent', 'value' => '20']]);

    expect(priceLines([$v->id => 1])->grandTotal->toDecimal())->toBe('800.00');
});

it('ignores inactive and out-of-window promotions', function (): void {
    $v = CartTestKit::variant('1000.00');
    Promotion::factory()->create(['is_active' => false]);
    Promotion::factory()->create(['starts_at' => now()->addDay()]);
    Promotion::factory()->create(['ends_at' => now()->subMinute()]);

    expect(priceLines([$v->id => 1])->promotions)->toBe([]);
});

it('applies the coupon after promotions, on the discounted amount', function (): void {
    $v = CartTestKit::variant('1000.00');
    Promotion::factory()->create(['rules' => ['type' => 'percent', 'value' => '10']]);
    Coupon::factory()->percent('10')->create(['code' => 'EID10']);

    $result = priceLines([$v->id => 1], 'eid10');

    // 1000 → 900 after the promotion → 10% coupon = 90.
    expect($result->coupon?->applied())->toBeTrue()
        ->and($result->couponDiscount->toDecimal())->toBe('90.00')
        ->and($result->grandTotal->toDecimal())->toBe('810.00');
    assertBreakdownSums($result);
});

it('reports a rejected coupon without discounting and blocks checkout readiness', function (): void {
    $v = CartTestKit::variant('1000.00');

    $result = priceLines([$v->id => 1], 'NOPE', 8);

    expect($result->coupon?->applied())->toBeFalse()
        ->and($result->coupon?->rejection?->value)->toBe('not_found')
        ->and($result->couponDiscount->toDecimal())->toBe('0.00')
        ->and($result->isCheckoutReady())->toBeFalse();
});

it('adds delivery from DeliveryChargeResolver — the same figure the estimator quotes', function (): void {
    $v = CartTestKit::variant('1000.00', variant: ['weight_grams' => 700]);
    Promotion::factory()->create(['rules' => ['type' => 'percent', 'value' => '10']]);

    $result = priceLines([$v->id => 2], districtId: 8);
    $estimator = app(DeliveryChargeResolver::class)->quote(District::query()->findOrFail(8), null, new DeliveryParcel([$v->id => 2], Money::of('1800.00')));

    // Chattogram (outside Dhaka) ৳130 + 1 started kg over 1 kg × ৳20.
    expect($result->shippingCharge?->toDecimal())->toBe('150.00')
        ->and($result->shippingCharge?->toDecimal())->toBe($estimator->charge?->toDecimal())
        ->and($result->delivery?->subtotal->toDecimal())->toBe('1800.00')
        ->and($result->grandTotal->toDecimal())->toBe('1950.00')
        ->and($result->isCheckoutReady())->toBeTrue();
    assertBreakdownSums($result);
});

it('measures the free-delivery threshold against the discounted goods', function (): void {
    $v = CartTestKit::variant('1600.00');
    Promotion::factory()->create(['rules' => ['type' => 'fixed', 'value' => '100']]);

    // 3200 → 3000 after promotion: exactly the ৳3000 threshold.
    expect(priceLines([$v->id => 2], districtId: 8)->shippingCharge?->toDecimal())->toBe('0.00');

    Promotion::factory()->create(['priority' => -1, 'rules' => ['type' => 'fixed', 'value' => '1']]);
    expect(priceLines([$v->id => 2], districtId: 8)->shippingCharge?->toDecimal())->toBe('130.00');
});

it('makes delivery free with a free-shipping coupon through the resolver seam', function (): void {
    $v = CartTestKit::variant('500.00');
    Coupon::factory()->freeShipping()->create(['code' => 'FREESHIP', 'min_order_amount' => '400.00']);

    $result = priceLines([$v->id => 1], 'FREESHIP', 8);

    expect($result->coupon?->applied())->toBeTrue()
        ->and($result->couponDiscount->toDecimal())->toBe('0.00')
        ->and($result->delivery?->freeShippingReason)->toBe('promotion')
        ->and($result->shippingCharge?->toDecimal())->toBe('0.00')
        ->and($result->grandTotal->toDecimal())->toBe('500.00');

    // The PDP estimator never carries a coupon: still charged.
    $pdp = app(DeliveryChargeResolver::class)->quote(District::query()->findOrFail(8), null, new DeliveryParcel([$v->id => 1]));
    expect($pdp->charge?->toDecimal())->toBe('130.00');
});

it('sums exactly when discounts split across awkward amounts', function (): void {
    $a = CartTestKit::variant('333.33');
    $b = CartTestKit::variant('166.67');
    $c = CartTestKit::variant('99.99');
    Promotion::factory()->create(['type' => PromotionType::Tiered, 'rules' => ['tiers' => [
        ['min_subtotal' => '500', 'type' => 'percent', 'value' => '7'],
        ['min_subtotal' => '1000', 'type' => 'percent', 'value' => '13'],
    ]]]);
    Coupon::factory()->fixed('100.01')->create(['code' => 'ODD']);

    $result = priceLines([$a->id => 3, $b->id => 1, $c->id => 1], 'ODD', 47);

    // 1266.65 → 13% tier = 164.66 (rounded) → coupon 100.01.
    expect($result->subtotal->toDecimal())->toBe('1266.65')
        ->and($result->promotionDiscount->toDecimal())->toBe('164.66')
        ->and($result->couponDiscount->toDecimal())->toBe('100.01');
    assertBreakdownSums($result);
});

it('gives the cheapest units free on buy X get Y', function (): void {
    $cheap = CartTestKit::variant('200.00');
    $dear = CartTestKit::variant('500.00');
    Promotion::factory()->create(['type' => PromotionType::BuyXGetY, 'rules' => ['buy' => 2, 'get' => 1]]);

    // 5 units → one group of 3 → 1 free unit, the ৳200 one.
    $result = priceLines([$cheap->id => 2, $dear->id => 3]);

    expect($result->promotionDiscount->toDecimal())->toBe('200.00');
    assertBreakdownSums($result);
});

it('discounts once per complete bundle', function (): void {
    $a = CartTestKit::variant('400.00');
    $b = CartTestKit::variant('600.00');
    Promotion::factory()->create(['type' => PromotionType::Bundle, 'rules' => [
        'product_ids' => [$a->product_id, $b->product_id], 'type' => 'fixed', 'value' => '150',
    ]]);

    expect(priceLines([$a->id => 2, $b->id => 1])->promotionDiscount->toDecimal())->toBe('150.00')
        ->and(priceLines([$a->id => 2])->promotionDiscount->toDecimal())->toBe('0.00');
});

it('scopes a category promotion to the whole subtree, minus exclusions', function (): void {
    $vehicles = Category::factory()->create(['name' => 'Vehicles', 'name_bn' => 'গাড়ি']);
    $cars = Category::factory()->create(['name' => 'Cars', 'name_bn' => 'কার']);
    $cars->appendToNode($vehicles)->save();
    $dolls = Category::factory()->create(['name_bn' => 'পুতুল']);

    $car = CartTestKit::variant('1000.00', category: $cars->refresh());
    $doll = CartTestKit::variant('1000.00', category: $dolls);
    $excluded = CartTestKit::variant('1000.00', category: $cars->refresh());
    Promotion::factory()->create(['type' => PromotionType::CategoryDiscount, 'rules' => [
        'scope' => ['applies_to' => 'categories', 'ids' => [$vehicles->id], 'exclude_product_ids' => [$excluded->product_id]],
        'type' => 'percent', 'value' => '25', 'max_discount' => '200',
    ]]);

    $result = priceLines([$car->id => 1, $doll->id => 1, $excluded->id => 1]);

    expect($result->lines[0]->promotionDiscount->toDecimal())->toBe('200.00') // 250 capped at 200
        ->and($result->lines[1]->promotionDiscount->toDecimal())->toBe('0.00')
        ->and($result->lines[2]->promotionDiscount->toDecimal())->toBe('0.00');
});

it('excludes unsellable variants from every figure and blocks checkout', function (): void {
    $live = CartTestKit::variant('1000.00');
    $archived = CartTestKit::variant('500.00', variant: ['status' => 'archived']);

    $result = priceLines([$live->id => 1, $archived->id => 1, 999_999 => 1], districtId: 8);

    expect($result->unavailableVariantIds)->toBe([$archived->id, 999_999])
        ->and($result->subtotal->toDecimal())->toBe('1000.00')
        ->and($result->isCheckoutReady())->toBeFalse();
});
