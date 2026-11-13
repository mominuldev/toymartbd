<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * `stock_adjustments.status` (P5-INV approval flow).
 *
 *   applied   at or below the approval threshold, or requested by someone who may
 *             approve: movements written when it was made
 *   pending   above the threshold: no movement yet, waiting in the approval queue
 *   approved  a pending adjustment an approver accepted: movements written then
 *   rejected  a pending adjustment an approver refused: nothing was ever written
 *
 * Only `applied` and `approved` have stock movements. A pending adjustment can only
 * become approved or rejected, once.
 */
enum StockAdjustmentStatus: string
{
    case Applied = 'applied';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Whether this adjustment has written its movements. */
    public function movedStock(): bool
    {
        return $this === self::Applied || $this === self::Approved;
    }
}
