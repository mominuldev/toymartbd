<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\DTOs\PricingLine;
use App\Domain\Marketing\Enums\ProductScopeType;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;
use DateTimeInterface;

/**
 * Free delivery over a threshold (P7, `free_delivery` promotions). The threshold
 * `rules.min_subtotal` is measured on the eligible lines' amounts AFTER promotions and
 * the coupon — the same post-discount figure the delivery resolver uses. The pipeline
 * picks the first candidate the promotion chain reached ({@see self::choose()}); the
 * delivery resolver then confirms it through {@see MarketingFreeShipping}.
 */
final class FreeDeliveryPromotions
{
    /**
     * @param  list<Promotion>  $candidates  in priority order
     * @param  list<PricingLine>  $lines
     * @param  array<int, Money>  $amounts  variant id → line amount after promotions and coupon
     */
    public function choose(array $candidates, array $lines, array $amounts): ?Promotion
    {
        foreach ($candidates as $promotion) {
            $scope = ProductScope::fromRules(is_array($promotion->rules['scope'] ?? null) ? $promotion->rules['scope'] : []);
            $eligible = Money::zero();
            foreach ($scope->filter($lines) as $line) {
                $eligible = $eligible->plus($amounts[$line->variantId] ?? Money::zero());
            }
            if ($eligible->greaterThan(Money::zero()) && ! $eligible->lessThan(self::threshold($promotion))) {
                return $promotion;
            }
        }

        return null;
    }

    /**
     * For the PDP estimator, which knows no customer and no coupon: a running, all-scope
     * free-delivery promotion without a per-customer limit whose threshold the subtotal meets.
     */
    public function forAnyone(Money $subtotal, DateTimeInterface $now): ?Promotion
    {
        foreach (Promotion::query()->runningAt($now)->where('type', PromotionType::FreeDelivery->value)->get() as $promotion) {
            $scope = is_array($promotion->rules['scope'] ?? null) ? $promotion->rules['scope'] : [];
            $all = ($scope['applies_to'] ?? 'all') === ProductScopeType::All->value
                && ($scope['exclude_product_ids'] ?? []) === [] && ($scope['exclude_category_ids'] ?? []) === [] && ($scope['exclude_brand_ids'] ?? []) === [];
            if ($all && $promotion->usage_limit_per_customer === null && PromotionEngine::available($promotion, null, null)
                && ! $subtotal->lessThan(self::threshold($promotion))) {
                return $promotion;
            }
        }

        return null;
    }

    public static function threshold(Promotion $promotion): Money
    {
        $min = $promotion->rules['min_subtotal'] ?? '0';

        return Money::of(is_string($min) || is_int($min) ? $min : '0');
    }
}
