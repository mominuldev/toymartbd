<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * `stock_adjustments.reason` (DATABASE-DESIGN §3.2), plus `gift_sample` — stock given
 * away as a gift, giveaway or influencer sample, which is a real outflow but neither a
 * loss nor a sale.
 *
 * Each reason fixes the direction it may move stock and the movement type it writes:
 * damage → damage_out; loss, expiry, gift/sample → adjustment_out; found →
 * adjustment_in; a recount or a correction may go either way.
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

    /** +1 only adds stock, -1 only removes it, 0 either. */
    public function direction(): int
    {
        return match ($this) {
            self::Found => 1,
            self::Recount, self::Correction => 0,
            default => -1,
        };
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
