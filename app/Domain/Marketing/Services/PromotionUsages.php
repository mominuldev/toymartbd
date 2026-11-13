<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\DTOs\AppliedDiscount;
use App\Domain\Marketing\Exceptions\PromotionRejected;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\PromotionUsage;
use App\Support\Money;
use App\Support\PhoneNumber;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes and reverses promotion redemptions (P7) — the promotion twin of CouponUsages.
 *
 * Both methods MUST run inside the caller's order transaction (PlaceOrder / EditOrder
 * own the boundary, rule 3) and refuse to run outside one. record() locks the applied
 * promotions FOR UPDATE in ascending id, then each flash sale's items in ascending
 * variant id (rule 9), and re-checks the total and per-phone limits, the budget and
 * the flash allotment under those locks: two concurrent checkouts can never both take
 * the last flash unit or the last of a budget.
 */
final class PromotionUsages
{
    /**
     * @param  list<AppliedDiscount>  $applied  the breakdown's promotions (free-delivery rows included)
     *
     * @throws PromotionRejected when a limit, budget or allotment ran out since pricing
     */
    public function record(array $applied, int $orderId, PhoneNumber $phone, ?int $customerId = null, ?DateTimeInterface $at = null): void
    {
        self::assertInTransaction();

        $byId = [];
        foreach ($applied as $discount) {
            if ($discount->source === AppliedDiscount::PROMOTION) {
                $byId[$discount->id] = $discount;
            }
        }
        ksort($byId);

        foreach ($byId as $id => $discount) {
            $promotion = Promotion::query()->lockForUpdate()->find($id);
            if ($promotion === null) {
                throw self::rejected($id, $discount->label, false);
            }

            if ($promotion->totalLimitReached()
                || ($promotion->usage_limit_per_customer !== null && self::countFor($promotion, $phone, $customerId) >= $promotion->usage_limit_per_customer)) {
                throw self::rejected($id, $discount->label, false);
            }
            $left = $promotion->budgetLeft();
            if ($left !== null && $discount->amount->greaterThan($left)) {
                throw self::rejected($id, $discount->label, false);
            }

            $units = $discount->flashUnits;
            ksort($units);
            if ($units !== []) {
                $items = FlashSaleItem::query()
                    ->where('promotion_id', $id)
                    ->whereIn('variant_id', array_keys($units))
                    ->orderBy('variant_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('variant_id');
                foreach ($units as $variantId => $take) {
                    $item = $items->get($variantId);
                    if (! $item instanceof FlashSaleItem || $item->remaining() < $take) {
                        throw self::rejected($id, $discount->label, true);
                    }
                }
                foreach ($units as $variantId => $take) {
                    $item = $items->get($variantId);
                    if ($item instanceof FlashSaleItem) {
                        $item->claimed += $take;
                        $item->save();
                    }
                }
            }

            PromotionUsage::query()->create([
                'promotion_id' => $id,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'phone' => $phone->e164(),
                'discount_amount' => $discount->amount,
                'flash_units' => $units === [] ? null : array_map('intval', $units),
                'used_at' => $at ?? now(),
            ]);

            $promotion->usage_count++;
            $promotion->discount_spent = $promotion->discount_spent->plus($discount->amount);
            $promotion->save();
        }
    }

    /** Reverses every promotion usage of an order (cancel, edit). Returns how many were reversed. */
    public function reverseForOrder(int $orderId): int
    {
        self::assertInTransaction();

        $usages = PromotionUsage::query()->where('order_id', $orderId)->orderBy('promotion_id')->get();
        foreach ($usages as $usage) {
            $promotion = Promotion::query()->lockForUpdate()->find($usage->promotion_id);
            $units = [];
            foreach ($usage->flash_units ?? [] as $variantId => $qty) {
                $units[(int) $variantId] = (int) $qty;
            }
            ksort($units);
            if ($units !== []) {
                $items = FlashSaleItem::query()
                    ->where('promotion_id', $usage->promotion_id)
                    ->whereIn('variant_id', array_keys($units))
                    ->orderBy('variant_id')
                    ->lockForUpdate()
                    ->get();
                foreach ($items as $item) {
                    $item->claimed = max(0, $item->claimed - ($units[$item->variant_id] ?? 0));
                    $item->save();
                }
            }
            if ($promotion !== null) {
                $promotion->usage_count = max(0, $promotion->usage_count - 1);
                $spent = $promotion->discount_spent->minus($usage->discount_amount);
                $promotion->discount_spent = $spent->isNegative() ? Money::zero() : $spent;
                $promotion->save();
            }
            $usage->delete();
        }

        return $usages->count();
    }

    /** Orders by this phone (E.164) or this customer that used the promotion. */
    public static function countFor(Promotion $promotion, ?PhoneNumber $phone, ?int $customerId): int
    {
        if ($phone === null && $customerId === null) {
            return 0;
        }

        return PromotionUsage::query()
            ->where('promotion_id', $promotion->id)
            ->where(function ($q) use ($phone, $customerId): void {
                if ($phone !== null) {
                    $q->orWhere('phone', $phone->e164());
                }
                if ($customerId !== null) {
                    $q->orWhere('customer_id', $customerId);
                }
            })
            ->count();
    }

    private static function rejected(int $id, string $label, bool $flash): PromotionRejected
    {
        return new PromotionRejected($id, $flash, $flash
            ? (string) __('cart.promotion.flash_sold_out')
            : (string) __('cart.promotion.unavailable', ['name' => $label]));
    }

    private static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Promotion usage must be written inside the order transaction.');
        }
    }
}
