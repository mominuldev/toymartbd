<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/** `stock_movements.type` (DATABASE-DESIGN §3.2). */
enum StockMovementType: string
{
    case PurchaseIn = 'purchase_in';
    case SaleOut = 'sale_out';
    case SaleCancelledIn = 'sale_cancelled_in';
    case CustomerReturnIn = 'customer_return_in';
    case CourierReturnIn = 'courier_return_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case DamageOut = 'damage_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case OpeningBalance = 'opening_balance';

    /** +1 for a type that adds stock, -1 for one that removes it. */
    public function sign(): int
    {
        return match ($this) {
            self::SaleOut, self::AdjustmentOut, self::DamageOut, self::TransferOut => -1,
            default => 1,
        };
    }

    /**
     * Whether a movement of this type may take on_hand below zero. None may at MVP:
     * a negative balance is always a bug or a miscount, and a miscount is corrected by
     * an explicit adjustment, not by letting a sale through. Kept as a method so a
     * future type (e.g. a back-order) is a one-line, reviewed change.
     */
    public function allowsNegativeBalance(): bool
    {
        return false;
    }
}
