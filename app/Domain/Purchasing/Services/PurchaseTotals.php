<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\Models\Purchase;
use App\Support\Money;

/**
 * PO money, computed only here (rule 1):
 *   line gross  = quantity × unit_cost, to the poisha
 *   line_total  = line gross − line discount
 *   total       = subtotal − discount + shipping_cost + customs_duty + other_cost + tax
 *   due_amount  = received_value − paid_amount
 *
 * @internal for the Purchasing actions
 */
final class PurchaseTotals
{
    public static function lineGross(PurchaseLineData $line): Money
    {
        return Money::of(Decimals::round(bcmul((string) $line->quantity, $line->unitCost, 8), 2));
    }

    public static function lineTotal(PurchaseLineData $line): Money
    {
        return self::lineGross($line)->minus($line->discount);
    }

    /** Refreshes `total` and `due_amount` from the stored components. */
    public static function refresh(Purchase $purchase): void
    {
        $purchase->total = $purchase->subtotal
            ->minus($purchase->discount)
            ->plus($purchase->shipping_cost)
            ->plus($purchase->customs_duty)
            ->plus($purchase->other_cost)
            ->plus($purchase->tax);
        $purchase->due_amount = $purchase->received_value->minus($purchase->paid_amount);
    }
}
