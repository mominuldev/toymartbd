<?php

declare(strict_types=1);

use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\Coupon;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Carbon;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Marketing\Promotions\PromoKit;

/*
 * P7 promotions engine: priority and stacking order, exclusivity stopping the chain, the
 * per-promotion coupon setting, every type, rounding, Dhaka-time windows, budget, and that
 * the breakdown always sums to the total.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
});

it('applies promotions in descending priority, each on what the previous left (compounding)', function (): void {
    $v = CartTestKit::variant('1000.00');
    $low = PromoKit::percentOff('10', ['name' => 'Low', 'priority' => 1]);
    $high = PromoKit::percentOff('20', ['name' => 'High', 'priority' => 5]);

    $b = PromoKit::price([$v->id => 1]);

    expect(array_map(fn ($d) => [$d->id, $d->amount->toDecimal()], $b->promotions))
        ->toBe([[$high->id, '200.00'], [$low->id, '80.00']])
        ->and($b->promotionDiscount->toDecimal())->toBe('280.00');
    PromoKit::assertSums($b);
});

it('breaks priority ties oldest first', function (): void {
    $v = CartTestKit::variant('1000.00');
    $first = PromoKit::percentOff('10', ['priority' => 3]);
    $second = PromoKit::promotion(PromotionType::Discount, ['type' => 'fixed', 'value' => '50'], ['priority' => 3]);

    $b = PromoKit::price([$v->id => 1]);

    expect(array_map(fn ($d) => $d->id, $b->promotions))->toBe([$first->id, $second->id])
        ->and($b->promotionDiscount->toDecimal())->toBe('150.00');
});

it('stops the chain at the first exclusive promotion that discounts, but not at one that matches nothing', function (): void {
    $car = CartTestKit::variant('1000.00');
    $other = CartTestKit::variant('500.00');
    // Exclusive but scoped to a product not in the cart: ignored, chain continues.
    PromoKit::promotion(PromotionType::Discount, ['scope' => ['applies_to' => 'products', 'ids' => [$other->product_id]], 'type' => 'percent', 'value' => '50'], ['priority' => 9, 'is_stackable' => false]);
    $exclusive = PromoKit::percentOff('10', ['priority' => 5, 'is_stackable' => false]);
    PromoKit::percentOff('30', ['priority' => 1]);

    $b = PromoKit::price([$car->id => 1]);

    expect(array_map(fn ($d) => $d->id, $b->promotions))->toBe([$exclusive->id])
        ->and($b->promotionDiscount->toDecimal())->toBe('100.00');
    PromoKit::assertSums($b);
});

it('lets a coupon follow an exclusive promotion by default (allows_coupons = true)', function (): void {
    $v = CartTestKit::variant('1000.00');
    PromoKit::percentOff('10', ['is_stackable' => false]);
    Coupon::factory()->percent('10')->create(['code' => 'EID10']);

    $b = PromoKit::price([$v->id => 1], 'EID10');

    expect($b->coupon?->applied())->toBeTrue()
        ->and($b->couponDiscount->toDecimal())->toBe('90.00')
        ->and($b->discountTotal()->toDecimal())->toBe('190.00');
    PromoKit::assertSums($b);
});

it('keeps a coupon off the lines of a promotion that does not allow coupons', function (): void {
    $sale = CartTestKit::variant('1000.00');
    $plain = CartTestKit::variant('400.00');
    PromoKit::promotion(PromotionType::Discount, ['scope' => ['applies_to' => 'products', 'ids' => [$sale->product_id]], 'type' => 'percent', 'value' => '20'], ['allows_coupons' => false]);
    Coupon::factory()->percent('10')->create(['code' => 'TEN']);

    $both = PromoKit::price([$sale->id => 1, $plain->id => 1], 'TEN');
    expect($both->coupon?->applied())->toBeTrue()
        ->and($both->couponDiscount->toDecimal())->toBe('40.00'); // only the plain line
    PromoKit::assertSums($both);

    $onlySale = PromoKit::price([$sale->id => 1], 'TEN');
    expect($onlySale->coupon?->rejection?->value)->toBe('not_combinable')
        ->and($onlySale->coupon?->message())->toContain('cannot be combined')
        ->and($onlySale->isCheckoutReady())->toBeFalse();
});

it('rounds percentages half away from zero and spreads order-level discounts without losing a poisha', function (): void {
    $a = CartTestKit::variant('333.33');
    $b = CartTestKit::variant('333.33');
    $c = CartTestKit::variant('333.34');
    PromoKit::percentOff('12.5');
    PromoKit::promotion(PromotionType::Tiered, ['tiers' => [['min_subtotal' => '500', 'type' => 'fixed', 'value' => '100.01']]], ['priority' => -1]);

    $out = PromoKit::price([$a->id => 1, $b->id => 1, $c->id => 1]);

    // 12.5% of 333.33 = 41.66625 → 41.67; of 333.34 = 41.6675 → 41.67
    expect($out->promotions[0]->amount->toDecimal())->toBe('125.01')
        ->and($out->promotions[1]->amount->toDecimal())->toBe('100.01')
        ->and($out->promotionDiscount->toDecimal())->toBe('225.02');
    PromoKit::assertSums($out);
});

it('prices buy-x-get-y, bundle price and tiers', function (): void {
    $cheap = CartTestKit::variant('200.00');
    $dear = CartTestKit::variant('500.00');
    PromoKit::promotion(PromotionType::BuyXGetY, ['buy' => 2, 'get' => 1]);
    $b = PromoKit::price([$cheap->id => 1, $dear->id => 2]);
    expect($b->promotionDiscount->toDecimal())->toBe('200.00'); // cheapest unit free
    PromoKit::assertSums($b);

    App\Domain\Marketing\Models\Promotion::query()->delete();
    PromoKit::promotion(PromotionType::Bundle, ['product_ids' => [$cheap->product_id, $dear->product_id], 'type' => 'price', 'value' => '600.00']);
    $b = PromoKit::price([$cheap->id => 2, $dear->id => 1]);
    // one complete set (200 + 500 = 700) sold for 600; the extra cheap unit stays at 200
    expect($b->promotionDiscount->toDecimal())->toBe('100.00')
        ->and($b->merchandiseTotal()->toDecimal())->toBe('800.00');
    PromoKit::assertSums($b);

    App\Domain\Marketing\Models\Promotion::query()->delete();
    PromoKit::promotion(PromotionType::Tiered, ['tiers' => [
        ['min_subtotal' => '1000', 'type' => 'percent', 'value' => '5'],
        ['min_subtotal' => '2000', 'type' => 'percent', 'value' => '10'],
    ]]);
    expect(PromoKit::price([$dear->id => 2])->promotionDiscount->toDecimal())->toBe('50.00')
        ->and(PromoKit::price([$dear->id => 4])->promotionDiscount->toDecimal())->toBe('200.00');
});

it('makes delivery free over the threshold measured after discounts, and names the promotion', function (): void {
    $v = CartTestKit::variant('1000.00');
    $free = PromoKit::promotion(PromotionType::FreeDelivery, ['min_subtotal' => '1800.00'], ['name' => 'Free delivery over 1800', 'name_bn' => '১৮০০ টাকার উপরে ফ্রি ডেলিভারি']);

    $two = PromoKit::price([$v->id => 2]);
    expect($two->shippingCharge?->toDecimal())->toBe('0.00')
        ->and(collect($two->itemised())->firstWhere('type', 'promotion'))->toMatchArray(['id' => $free->id, 'label' => 'Free delivery over 1800'])
        ->and($two->grandTotal->toDecimal())->toBe('2000.00');
    PromoKit::assertSums($two);

    // A 15% discount brings 2000 to 1700 — below the threshold: delivery is charged.
    PromoKit::percentOff('15', ['priority' => 10]);
    $after = PromoKit::price([$v->id => 2]);
    expect($after->shippingCharge?->isZero())->toBeFalse()
        ->and(collect($after->promotions)->pluck('id')->all())->not->toContain($free->id);
    PromoKit::assertSums($after);
});

it('skips a free-delivery promotion the exclusive chain never reached', function (): void {
    $v = CartTestKit::variant('1000.00');
    PromoKit::percentOff('5', ['priority' => 10, 'is_stackable' => false]);
    PromoKit::promotion(PromotionType::FreeDelivery, ['min_subtotal' => '100.00'], ['priority' => 1]);

    expect(PromoKit::price([$v->id => 2])->shippingCharge?->isZero())->toBeFalse();
});

it('runs from starts_at inclusive to ends_at exclusive, with admin times read in Dhaka time', function (): void {
    $v = CartTestKit::variant('1000.00');
    // 2026-12-01 00:00 Dhaka = 2026-11-30 18:00 UTC
    $start = Carbon::parse('2026-12-01 00:00', 'Asia/Dhaka');
    $end = Carbon::parse('2026-12-02 00:00', 'Asia/Dhaka');
    PromoKit::percentOff('10', ['starts_at' => $start, 'ends_at' => $end]);

    expect(PromoKit::price([$v->id => 1], now: $start->copy()->subSecond())->promotionDiscount->isZero())->toBeTrue()
        ->and(PromoKit::price([$v->id => 1], now: $start)->promotionDiscount->toDecimal())->toBe('100.00')
        ->and(PromoKit::price([$v->id => 1], now: $end->copy()->subSecond())->promotionDiscount->toDecimal())->toBe('100.00')
        ->and(PromoKit::price([$v->id => 1], now: $end)->promotionDiscount->isZero())->toBeTrue()
        ->and($start->utc()->format('Y-m-d H:i'))->toBe('2026-11-30 18:00');
});

it('caps a discount at the budget left and stops once it is spent', function (): void {
    $v = CartTestKit::variant('1000.00');
    $p = PromoKit::percentOff('50', ['budget' => '1000.00']);
    $p->forceFill(['discount_spent' => '700.00'])->save();

    expect(PromoKit::price([$v->id => 1])->promotionDiscount->toDecimal())->toBe('300.00');

    $p->forceFill(['discount_spent' => '1000.00'])->save();
    expect(PromoKit::price([$v->id => 1])->promotionDiscount->isZero())->toBeTrue();
});

it('stops at the total usage limit', function (): void {
    $v = CartTestKit::variant('1000.00');
    $p = PromoKit::percentOff('10', ['usage_limit_total' => 2]);
    $p->forceFill(['usage_count' => 2])->save();

    expect(PromoKit::price([$v->id => 1])->promotions)->toBe([]);
});

it('always sums: mixed types, coupon and delivery together', function (): void {
    $a = CartTestKit::variant('777.77');
    $b = CartTestKit::variant('123.45');
    PromoKit::percentOff('7', ['priority' => 3]);
    PromoKit::promotion(PromotionType::BuyXGetY, ['buy' => 1, 'get' => 1, 'percent' => '33'], ['priority' => 2]);
    PromoKit::promotion(PromotionType::Tiered, ['tiers' => [['min_subtotal' => '100', 'type' => 'fixed', 'value' => '19.99']]], ['priority' => 1]);
    Coupon::factory()->percent('3')->create(['code' => 'ODD']);

    foreach ([[1, 1], [2, 3], [5, 1]] as [$qa, $qb]) {
        PromoKit::assertSums(PromoKit::price([$a->id => $qa, $b->id => $qb], 'ODD'));
    }
});
