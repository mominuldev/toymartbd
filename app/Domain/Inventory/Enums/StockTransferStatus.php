<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * `stock_transfers.status`. The specs (PRD I-14, 06-admin-feature-checklist §2.7) define
 * no in-transit state, so a transfer is booked in one transaction: the units leave the
 * source and arrive at the destination together (`completed`). An in-transit step
 * (dispatched → received, with `incoming` on the destination) is an additive case here
 * if the owner wants one — open question in the P5 report.
 */
enum StockTransferStatus: string
{
    case Completed = 'completed';
}
