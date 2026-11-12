<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

/** `refunds.status`. The approval flow (threshold, approved_by) is Phase 5 (§4.7). */
enum RefundStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
