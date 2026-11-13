<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\DTOs\AppliedDiscount;
use App\Domain\Marketing\DTOs\PricingLine;
use App\Domain\Marketing\DTOs\PromotionOutcome;
use App\Domain\Marketing\Enums\DiscountKind;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;
use App\Support\PhoneNumber;
use DateTimeInterface;

/**
 * The promotion stage of the pricing pipeline (P4-CRT-02, P7; docs/api/promotions.md).
 *
 * Running promotions are applied in descending `priority` (ties: oldest first). Each
 * works on the line amounts left by the ones before it, so stacked discounts compound
 * rather than double-count. The first non-stackable promotion that actually takes
 * money off stops the chain; one that matches nothing does not.
 *
 * A promotion is skipped (as if not running) when its total usage limit is reached,
 * its budget is spent, or — when the phone/customer is known (checkout) — that
 * customer has used it `usage_limit_per_customer` times. A partly spent budget caps
 * the discount at what is left. `free_delivery` promotions discount no line: the ones
 * the chain reaches are handed to the delivery stage as candidates.
 *
 * Read-only; no transaction. PromotionUsages re-checks every limit, budget and flash
 * allotment under row locks when the order is written.
 */
final class PromotionEngine
{
    /**
     * @param  list<PricingLine>  $lines
     * @param  array<int, Money>|null  $remaining  variant id → line amount entering the stage (default: base subtotals)
     */
    public function apply(array $lines, DateTimeInterface $now, ?array $remaining = null, ?PhoneNumber $phone = null, ?int $customerId = null): PromotionOutcome
    {
        $remaining ??= self::baseAmounts($lines);
        $discounts = [];
        $freeDelivery = [];

        foreach (Promotion::query()->runningAt($now)->with('flashItems')->get() as $promotion) {
            if (! self::available($promotion, $phone, $customerId)) {
                continue;
            }

            if ($promotion->type === PromotionType::FreeDelivery) {
                $freeDelivery[] = $promotion;

                continue;
            }

            $flashUnits = [];
            $perLine = $this->discountFor($promotion, $lines, $remaining, $flashUnits);
            $perLine = array_filter($perLine, static fn (Money $m): bool => $m->greaterThan(Money::zero()));
            if ($perLine === []) {
                continue;
            }

            foreach ($perLine as $variantId => $amount) {
                $remaining[$variantId] = $remaining[$variantId]->minus($amount);
            }

            $discounts[] = new AppliedDiscount(
                AppliedDiscount::PROMOTION, $promotion->id, $promotion->name, $promotion->name_bn, Allocator::sum($perLine), $perLine,
                allowsCoupons: $promotion->allows_coupons,
                flashUnits: array_intersect_key($flashUnits, $perLine),
                kind: $promotion->type->value,
            );

            if (! $promotion->is_stackable) {
                return new PromotionOutcome($discounts, $remaining, $promotion->id, $freeDelivery);
            }
        }

        return new PromotionOutcome($discounts, $remaining, null, $freeDelivery);
    }

    /** Limits and budget that do not depend on the cart's contents. */
    public static function available(Promotion $promotion, ?PhoneNumber $phone, ?int $customerId): bool
    {
        if ($promotion->totalLimitReached()) {
            return false;
        }
        $left = $promotion->budgetLeft();
        if ($left !== null && ! $left->greaterThan(Money::zero())) {
            return false;
        }

        return $promotion->usage_limit_per_customer === null
            || ($phone === null && $customerId === null)
            || PromotionUsages::countFor($promotion, $phone, $customerId) < $promotion->usage_limit_per_customer;
    }

    /**
     * @param  list<PricingLine>  $lines
     * @return array<int, Money>
     */
    public static function baseAmounts(array $lines): array
    {
        $amounts = [];
        foreach ($lines as $line) {
            $amounts[$line->variantId] = $line->subtotal();
        }

        return $amounts;
    }

    /**
     * @param  list<PricingLine>  $lines
     * @param  array<int, Money>  $remaining
     * @param  array<int, int>  $flashUnits  out: flash units per variant
     * @return array<int, Money> variant id → discount, each ≤ that line's remaining amount
     */
    private function discountFor(Promotion $promotion, array $lines, array $remaining, array &$flashUnits): array
    {
        $rules = $promotion->rules;
        if ($promotion->type === PromotionType::FlashSale && $promotion->flashItems->isNotEmpty()) {
            $perLine = $this->flash($promotion, $lines, $remaining, $flashUnits);

            return Allocator::cap($perLine, self::budgetCap($promotion, null));
        }

        $scope = ProductScope::fromRules(is_array($rules['scope'] ?? null) ? $rules['scope'] : []);
        $eligible = $scope->filter($lines);
        if ($eligible === []) {
            return [];
        }

        $perLine = match ($promotion->type) {
            PromotionType::Discount, PromotionType::FlashSale, PromotionType::CategoryDiscount => $this->perLine($eligible, $remaining, $rules),
            PromotionType::Tiered => $this->tiered($eligible, $remaining, $rules),
            PromotionType::BuyXGetY => $this->buyXGetY($eligible, $remaining, $rules),
            PromotionType::Bundle => $this->bundle($eligible, $remaining, $rules),
            PromotionType::FreeDelivery => [],
        };

        return Allocator::cap($perLine, self::budgetCap($promotion, self::money($rules['max_discount'] ?? null)));
    }

    /** The smaller of `max_discount` and the budget left (null = uncapped). */
    private static function budgetCap(Promotion $promotion, ?Money $maxDiscount): ?Money
    {
        $left = $promotion->budgetLeft();
        if ($left === null) {
            return $maxDiscount;
        }

        return $maxDiscount === null ? $left : self::min($maxDiscount, $left);
    }

    /**
     * Flash sale with items: each listed variant at its sale price, for at most the units
     * left in its allotment (and its per-order limit); further units stay at full price.
     *
     * @param  list<PricingLine>  $lines
     * @param  array<int, Money>  $remaining
     * @param  array<int, int>  $flashUnits  out: variant id → units at the sale price
     * @return array<int, Money>
     */
    private function flash(Promotion $promotion, array $lines, array $remaining, array &$flashUnits): array
    {
        $items = $promotion->flashItems->keyBy('variant_id');
        $out = [];
        foreach ($lines as $line) {
            $item = $items->get($line->variantId);
            if (! $item instanceof FlashSaleItem || ! $item->sale_price->lessThan($line->unitPrice)) {
                continue;
            }
            $take = min($line->quantity, $item->remaining(), $item->per_order_limit ?? $line->quantity);
            if ($take < 1) {
                continue;
            }
            $amount = $line->unitPrice->minus($item->sale_price)->times($take);
            $out[$line->variantId] = self::min($amount, $remaining[$line->variantId]);
            $flashUnits[$line->variantId] = $take;
        }

        return $out;
    }

    /**
     * Percent of each eligible line, or a fixed amount per unit.
     *
     * @param  list<PricingLine>  $eligible
     * @param  array<int, Money>  $remaining
     * @param  array<string, mixed>  $rules
     * @return array<int, Money>
     */
    private function perLine(array $eligible, array $remaining, array $rules): array
    {
        [$kind, $value] = self::discount($rules);
        if ($kind === null || $kind === DiscountKind::Price) {
            return [];
        }

        $out = [];
        foreach ($eligible as $line) {
            $left = $remaining[$line->variantId];
            $amount = $kind === DiscountKind::Percent ? $left->percentage($value->amount) : $value->times($line->quantity);
            $out[$line->variantId] = self::min($amount, $left);
        }

        return $out;
    }

    /**
     * The highest tier whose `min_subtotal` the eligible amount reaches; spread over the eligible lines.
     *
     * @param  list<PricingLine>  $eligible
     * @param  array<int, Money>  $remaining
     * @param  array<string, mixed>  $rules
     * @return array<int, Money>
     */
    private function tiered(array $eligible, array $remaining, array $rules): array
    {
        $bases = self::pick($remaining, $eligible);
        $subtotal = Allocator::sum($bases);

        $best = null;
        foreach (is_array($rules['tiers'] ?? null) ? $rules['tiers'] : [] as $tier) {
            $min = is_array($tier) ? self::money($tier['min_subtotal'] ?? null) : null;
            if ($min === null || $subtotal->lessThan($min)) {
                continue;
            }
            if ($best === null || $min->greaterThan($best[0])) {
                $best = [$min, $tier];
            }
        }
        if ($best === null) {
            return [];
        }

        [$kind, $value] = self::discount($best[1]);
        if ($kind === null || $kind === DiscountKind::Price) {
            return [];
        }
        $amount = $kind === DiscountKind::Percent ? $subtotal->percentage($value->amount) : self::min($value, $subtotal);

        return Allocator::spread($amount, $bases);
    }

    /**
     * For every (buy + get) eligible units, the `get` cheapest are discounted by `percent` (default 100 = free).
     *
     * @param  list<PricingLine>  $eligible
     * @param  array<int, Money>  $remaining
     * @param  array<string, mixed>  $rules
     * @return array<int, Money>
     */
    private function buyXGetY(array $eligible, array $remaining, array $rules): array
    {
        $buy = (int) ($rules['buy'] ?? 0);
        $get = (int) ($rules['get'] ?? 0);
        $percent = is_numeric($rules['percent'] ?? null) ? (string) $rules['percent'] : '100';
        if ($buy < 1 || $get < 1) {
            return [];
        }

        $units = array_sum(array_map(static fn (PricingLine $l): int => $l->quantity, $eligible));
        $free = intdiv($units, $buy + $get) * $get;
        if ($free === 0) {
            return [];
        }

        // Cheapest current unit price first; ties by variant id for a stable answer.
        usort($eligible, static fn (PricingLine $a, PricingLine $b): int => [intdiv($remaining[$a->variantId]->toPoisha(), $a->quantity), $a->variantId]
            <=> [intdiv($remaining[$b->variantId]->toPoisha(), $b->quantity), $b->variantId]);

        $out = [];
        foreach ($eligible as $line) {
            if ($free === 0) {
                break;
            }
            $take = min($free, $line->quantity);
            $free -= $take;
            $value = self::unitsValue($remaining[$line->variantId], $take, $line->quantity);
            $out[$line->variantId] = $value->percentage($percent);
        }

        return $out;
    }

    /**
     * Once per complete set of `product_ids` (each at least once in the cart).
     *
     * @param  list<PricingLine>  $eligible
     * @param  array<int, Money>  $remaining
     * @param  array<string, mixed>  $rules
     * @return array<int, Money>
     */
    private function bundle(array $eligible, array $remaining, array $rules): array
    {
        $productIds = array_values(array_unique(array_map('intval', is_array($rules['product_ids'] ?? null) ? $rules['product_ids'] : [])));
        [$kind, $value] = self::discount($rules);
        if (count($productIds) < 2 || $kind === null) {
            return [];
        }

        $byProduct = [];
        foreach ($eligible as $line) {
            $byProduct[$line->productId][] = $line;
        }

        $sets = PHP_INT_MAX;
        foreach ($productIds as $productId) {
            $units = array_sum(array_map(static fn (PricingLine $l): int => $l->quantity, $byProduct[$productId] ?? []));
            $sets = min($sets, $units);
        }
        if ($sets === 0) {
            return [];
        }

        // The value of `sets` units of each product, taken line by line.
        $bases = [];
        foreach ($productIds as $productId) {
            $need = $sets;
            foreach ($byProduct[$productId] as $line) {
                if ($need === 0) {
                    break;
                }
                $take = min($need, $line->quantity);
                $need -= $take;
                $bases[$line->variantId] = self::unitsValue($remaining[$line->variantId], $take, $line->quantity);
            }
        }

        $bundleValue = Allocator::sum($bases);
        $amount = match ($kind) {
            DiscountKind::Percent => $bundleValue->percentage($value->amount),
            DiscountKind::Fixed => self::min($value->times($sets), $bundleValue),
            // Bundle price: each set sells for `value`; never a negative discount.
            DiscountKind::Price => $bundleValue->greaterThan($value->times($sets)) ? $bundleValue->minus($value->times($sets)) : Money::zero(),
        };

        return Allocator::spread($amount, $bases);
    }

    /** The exact share of a line amount for `take` of its `quantity` units. */
    private static function unitsValue(Money $lineAmount, int $take, int $quantity): Money
    {
        return $take >= $quantity ? $lineAmount : $lineAmount->allocate($take, $quantity - $take)[0];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{DiscountKind|null, Money}
     */
    private static function discount(array $rules): array
    {
        $kind = DiscountKind::tryFrom(is_string($rules['type'] ?? null) ? $rules['type'] : '');
        $value = self::money($rules['value'] ?? null);

        return $value === null || ! $value->greaterThan(Money::zero()) ? [null, Money::zero()] : [$kind, $value];
    }

    private static function money(mixed $value): ?Money
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) === 1)) {
            return Money::of($value);
        }

        return null;
    }

    private static function min(Money $a, Money $b): Money
    {
        return $a->lessThan($b) ? $a : $b;
    }

    /**
     * @param  array<int, Money>  $remaining
     * @param  list<PricingLine>  $lines
     * @return array<int, Money>
     */
    private static function pick(array $remaining, array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[$line->variantId] = $remaining[$line->variantId];
        }

        return $out;
    }
}
