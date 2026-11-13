<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** A person's decision on a settlement line (`courier_settlement_items.review_status`). */
enum SettlementReview: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Excluded = 'excluded';
}
