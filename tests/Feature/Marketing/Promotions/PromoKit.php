<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing\Promotions;

use App\Domain\Cart\DTOs\PriceBreakdown;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Services\PricingPipeline;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;
use App\Support\PhoneNumber;
use DateTimeInterface;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Finance\FinanceKit;

/** Fixtures for the P7 promotion, flash-sale and stacking tests. */
final class PromoKit
{
    /** @param  array<string, mixed>  $rules  @param  array<string, mixed>  $attributes */
    public static function promotion(PromotionType $type, array $rules, array $attributes = []): Promotion
    {
        return Promotion::factory()->rules($type, $rules)->create([
            'name' => 'Puja sale', 'name_bn' => 'পূজার ছাড়', ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function percentOff(string $percent, array $attributes = []): Promotion
    {
        return self::promotion(PromotionType::Discount, ['scope' => ['applies_to' => 'all'], 'type' => 'percent', 'value' => $percent], $attributes);
    }

    /** @param  array<int, array{0: string, 1: int, 2?: int|null}>  $items  variant id → [sale price, allotment, per-order limit] */
    public static function flash(array $items, array $attributes = []): Promotion
    {
        $promotion = self::promotion(PromotionType::FlashSale, ['scope' => ['applies_to' => 'all']], [
            'name' => 'Flash sale', 'name_bn' => 'ফ্ল্যাশ সেল', 'priority' => 100,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), ...$attributes,
        ]);
        foreach ($items as $variantId => $item) {
            FlashSaleItem::query()->create([
                'promotion_id' => $promotion->id, 'variant_id' => $variantId, 'sale_price' => $item[0],
                'allotment' => $item[1], 'per_order_limit' => $item[2] ?? null,
            ]);
        }

        return $promotion->refresh();
    }

    /** @param  array<int, int>  $lines */
    public static function price(array $lines, ?string $coupon = null, bool $destination = true, ?string $phone = null, ?DateTimeInterface $now = null): PriceBreakdown
    {
        return app(PricingPipeline::class)->price(new PricingInput(
            lines: $lines,
            couponCode: $coupon,
            district: $destination ? District::query()->findOrFail(CheckoutKit::DISTRICT) : null,
            upazila: $destination ? Upazila::query()->findOrFail(CheckoutKit::UPAZILA) : null,
            phone: $phone === null ? null : PhoneNumber::parse($phone),
            now: $now,
        ));
    }

    /** Σ itemised rows = grand total, Σ lines = goods after discounts (PriceBreakdown also asserts it). */
    public static function assertSums(PriceBreakdown $b): void
    {
        $rows = array_reduce($b->itemised(), static fn (Money $c, array $r): Money => $c->plus($r['amount']), Money::zero());
        $lines = Money::zero();
        foreach ($b->lines as $line) {
            $lines = $lines->plus($line->total);
            expect($line->subtotal->minus($line->promotionDiscount)->minus($line->couponDiscount)->equals($line->total))->toBeTrue();
        }
        expect($rows->toDecimal())->toBe($b->grandTotal->toDecimal())
            ->and($lines->toDecimal())->toBe($b->merchandiseTotal()->toDecimal());
    }

    public static function assertInvariants(): void
    {
        expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 0]);
        FinanceKit::assertFinanceInvariant();
    }
}
