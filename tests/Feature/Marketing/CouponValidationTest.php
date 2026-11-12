<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Marketing\Contracts\CustomerOrderHistory;
use App\Domain\Marketing\DTOs\PricingLine;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Domain\Marketing\Services\CouponEvaluator;
use App\Domain\Marketing\Services\CouponUsages;
use App\Domain\Marketing\Services\PromotionEngine;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/*
 * P4-CRT-04 — window, minimum, total and per-customer limits (keyed on phone), scope,
 * exclusions; usage recording under the coupon's row lock.
 */

function couponLine(int $variantId, string $price, int $qty = 1, int $productId = 1, ?int $brandId = null, array $categories = []): PricingLine
{
    return new PricingLine($variantId, $productId, $brandId, $categories, Money::of($price), $qty);
}

function evaluateCoupon(string $code, array $lines, ?string $phone = null, ?int $customerId = null): App\Domain\Marketing\DTOs\CouponOutcome
{
    return app(CouponEvaluator::class)->evaluate(
        $code, $lines, PromotionEngine::baseAmounts($lines), now(), $phone === null ? null : PhoneNumber::parse($phone), $customerId,
    );
}

function recordUsage(Coupon $coupon, int $orderId, string $phone, string $discount = '100.00', ?int $customerId = null): CouponUsage
{
    return DB::transaction(fn () => app(CouponUsages::class)->record($coupon, $orderId, PhoneNumber::parse($phone), Money::of($discount), $customerId));
}

it('matches codes case-insensitively and rejects unknown ones', function (): void {
    Coupon::factory()->percent('10')->create(['code' => 'PUJA10']);

    expect(evaluateCoupon(' puja10 ', [couponLine(1, '1000')])->discount?->amount->toDecimal())->toBe('100.00')
        ->and(evaluateCoupon('PUJA11', [couponLine(1, '1000')])->rejection?->value)->toBe('not_found');
});

it('enforces active flag and the time window', function (): void {
    Coupon::factory()->create(['code' => 'OFF', 'is_active' => false]);
    Coupon::factory()->create(['code' => 'SOON', 'starts_at' => now()->addHour()]);
    Coupon::factory()->create(['code' => 'GONE', 'ends_at' => now()->subSecond()]);
    Coupon::factory()->create(['code' => 'OPEN', 'starts_at' => null, 'ends_at' => null]);

    $lines = [couponLine(1, '1000')];
    expect(evaluateCoupon('OFF', $lines)->rejection?->value)->toBe('inactive')
        ->and(evaluateCoupon('SOON', $lines)->rejection?->value)->toBe('not_started')
        ->and(evaluateCoupon('GONE', $lines)->rejection?->value)->toBe('expired')
        ->and(evaluateCoupon('OPEN', $lines)->applied())->toBeTrue();
});

it('enforces the minimum order with a localised message naming it', function (): void {
    Coupon::factory()->fixed('100')->create(['code' => 'MIN', 'min_order_amount' => '1500.00']);

    $outcome = evaluateCoupon('MIN', [couponLine(1, '1499.99')]);
    expect($outcome->rejection?->value)->toBe('minimum_not_met')
        ->and($outcome->message())->toBe('Spend at least ৳1500.00 to use this coupon.');

    app()->setLocale('bn');
    expect(evaluateCoupon('MIN', [couponLine(1, '1499.99')])->message())->toContain('কমপক্ষে ৳1500.00');
    app()->setLocale('en');

    expect(evaluateCoupon('MIN', [couponLine(1, '1500.00')])->applied())->toBeTrue();
});

it('caps a fixed coupon at the eligible goods and a percent coupon at max_discount', function (): void {
    Coupon::factory()->fixed('500')->create(['code' => 'BIG']);
    Coupon::factory()->percent('50')->create(['code' => 'HALF', 'max_discount' => '300.00']);

    expect(evaluateCoupon('BIG', [couponLine(1, '350.00')])->discount?->amount->toDecimal())->toBe('350.00')
        ->and(evaluateCoupon('HALF', [couponLine(1, '1000.00')])->discount?->amount->toDecimal())->toBe('300.00');
});

it('applies only to scoped products, categories (subtree) and brands, minus exclusions', function (): void {
    $lego = Brand::factory()->create(['name' => 'LEGO', 'name_bn' => 'লেগো']);
    $toys = Category::factory()->create(['name_bn' => 'খেলনা']);
    $puzzles = Category::factory()->create(['name_bn' => 'ধাঁধা']);
    $puzzles->appendToNode($toys)->save();

    Coupon::factory()->percent('10')->create(['code' => 'BRAND', 'applies_to' => ProductScopeType::Brands, 'conditions' => ['ids' => [$lego->id]]]);
    Coupon::factory()->percent('10')->create(['code' => 'CAT', 'applies_to' => ProductScopeType::Categories, 'conditions' => ['ids' => [$toys->id]],
        'excludes' => ['product_ids' => [99]]]);
    Coupon::factory()->percent('10')->create(['code' => 'PROD', 'applies_to' => ProductScopeType::Products, 'conditions' => ['ids' => [7]],
        'excludes' => ['brand_ids' => [$lego->id]]]);

    $legoLine = couponLine(1, '1000', productId: 7, brandId: $lego->id);
    $puzzleLine = couponLine(2, '500', productId: 8, categories: [$puzzles->id]);
    $excludedPuzzle = couponLine(3, '500', productId: 99, categories: [$puzzles->id]);

    expect(evaluateCoupon('BRAND', [$legoLine, $puzzleLine])->discount?->amount->toDecimal())->toBe('100.00')
        ->and(evaluateCoupon('CAT', [$legoLine, $puzzleLine, $excludedPuzzle])->discount?->perLine)->toHaveKeys([2])
        ->and(evaluateCoupon('CAT', [$legoLine, $puzzleLine, $excludedPuzzle])->discount?->amount->toDecimal())->toBe('50.00')
        ->and(evaluateCoupon('CAT', [$excludedPuzzle])->rejection?->value)->toBe('not_applicable')
        ->and(evaluateCoupon('PROD', [$legoLine])->rejection?->value)->toBe('not_applicable');
});

it('enforces the total usage limit', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'ONCE', 'usage_limit_total' => 1]);
    recordUsage($coupon, 1, '01712345678');

    expect(evaluateCoupon('ONCE', [couponLine(1, '1000')])->rejection?->value)->toBe('usage_limit_reached');
    DB::transaction(function () use ($coupon): void {
        expect(fn () => app(CouponUsages::class)->record($coupon, 2, PhoneNumber::parse('01812345678'), Money::of('10')))
            ->toThrow(CouponRejected::class);
    });
});

it('holds the per-customer limit across guest checkouts on the same phone', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'NEWBIE', 'usage_limit_per_customer' => 1]);

    // First guest checkout — national format, no customer account.
    $usage = recordUsage($coupon, 101, '01712-345678');
    expect($usage->phone)->toBe('+8801712345678');

    // A second guest checkout, same phone typed differently (and in Bengali digits).
    expect(evaluateCoupon('NEWBIE', [couponLine(1, '1000')], '+880 1712 345678')->rejection?->value)->toBe('customer_limit_reached')
        ->and(evaluateCoupon('NEWBIE', [couponLine(1, '1000')], '০১৭১২৩৪৫৬৭৮')->rejection?->value)->toBe('customer_limit_reached')
        ->and(evaluateCoupon('NEWBIE', [couponLine(1, '1000')], '01812345678')->applied())->toBeTrue()
        // Without a phone (cart before checkout) the check cannot be made.
        ->and(evaluateCoupon('NEWBIE', [couponLine(1, '1000')])->applied())->toBeTrue();

    // The write path re-checks under the coupon's lock: a racing second use is refused.
    DB::transaction(function () use ($coupon): void {
        expect(fn () => app(CouponUsages::class)->record($coupon, 102, PhoneNumber::parse('8801712345678'), Money::of('10')))
            ->toThrow(CouponRejected::class);
    });

    expect(CouponUsage::query()->count())->toBe(1)
        ->and($coupon->refresh()->used_count)->toBe(1);
});

it('counts a registered customer\'s usages on another phone too', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'MINE', 'usage_limit_per_customer' => 1]);
    recordUsage($coupon, 1, '01712345678', customerId: 42);

    expect(evaluateCoupon('MINE', [couponLine(1, '1000')], '01912345678', 42)->rejection?->value)->toBe('customer_limit_reached');
});

// CouponUsages refuses to run outside a transaction (DB::transactionLevel() === 0); RefreshDatabase
// runs every test inside one, so — as for StockLedger — that one-line guard is not testable here.

it('reverses an order\'s usage on cancellation, freeing the limit', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'BACK', 'usage_limit_per_customer' => 1]);
    recordUsage($coupon, 7, '01712345678');

    expect(DB::transaction(fn () => app(CouponUsages::class)->reverseForOrder(7)))->toBe(1)
        ->and($coupon->refresh()->used_count)->toBe(0)
        ->and(evaluateCoupon('BACK', [couponLine(1, '1000')], '01712345678')->applied())->toBeTrue();
});

it('rejects a first-order coupon for a phone with order history', function (): void {
    Coupon::factory()->create(['code' => 'FIRST', 'first_order_only' => true]);
    app()->instance(CustomerOrderHistory::class, new class implements CustomerOrderHistory
    {
        public function hasOrdered(PhoneNumber $phone): bool
        {
            return $phone->national() === '01712345678';
        }
    });

    expect(evaluateCoupon('FIRST', [couponLine(1, '1000')], '01712345678')->rejection?->value)->toBe('first_order_only')
        ->and(evaluateCoupon('FIRST', [couponLine(1, '1000')], '01812345678')->applied())->toBeTrue();
});
