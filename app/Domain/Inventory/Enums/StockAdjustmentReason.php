<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * `stock_adjustments.reason` (DATABASE-DESIGN §3.2), plus `gift_sample` — stock given
 * away as a gift, giveaway or influencer sample, which is a real outflow but neither a
 * loss nor a sale — and `theft` (P5-INV), a loss kept apart from `loss` so shrinkage
 * by theft is reportable on its own.
 *
 * Each reason fixes the direction it may move stock and the movement type it writes:
 * damage → damage_out; loss, theft, expiry, gift/sample → adjustment_out; found →
 * adjustment_in; a recount or a correction may go either way.
 *
 * Damage, loss, theft and expiry are write-offs: the units are gone with no sale, so
 * Finance books their weighted-average cost as an `inventory_write_off` expense
 * (StockWrittenOff). A gift/sample is a marketing cost, not a write-off, and is left
 * for the expense module.
 */
enum StockAdjustmentReason: string
{
    case Damage = 'damage';
    case Loss = 'loss';
    case Found = 'found';
    case Recount = 'recount';
    case Expiry = 'expiry';
    case Correction = 'correction';
    case GiftSample = 'gift_sample';
    case Theft = 'theft';

    /** +1 only adds stock, -1 only removes it, 0 either. */
    public function direction(): int
    {
        return match ($this) {
            self::Found => 1,
            self::Recount, self::Correction => 0,
            default => -1,
        };
    }

    /** Whether removing stock for this reason is a P&L write-off (StockWrittenOff). */
    public function isWriteOff(): bool
    {
        return in_array($this, [self::Damage, self::Loss, self::Theft, self::Expiry], true);
    }

    public function allows(int $delta): bool
    {
        return $delta !== 0 && ($this->direction() === 0 || $this->direction() === ($delta <=> 0));
    }

    /** The ledger movement type for a change of this sign. */
    public function movementType(int $delta): StockMovementType
    {
        if ($delta > 0) {
            return StockMovementType::AdjustmentIn;
        }

        return $this === self::Damage ? StockMovementType::DamageOut : StockMovementType::AdjustmentOut;
    }
}
