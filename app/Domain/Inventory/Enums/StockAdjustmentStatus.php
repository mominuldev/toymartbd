<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * `stock_adjustments.status`. MVP applies an adjustment immediately; the approval step
 * (`pending` → `applied`, with `approved_by`) arrives with Phase 5 purchasing.
 */
enum StockAdjustmentStatus: string
{
    case Applied = 'applied';
}
