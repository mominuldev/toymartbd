<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/** `orders.verification_status` — the human phone check for risky orders (§5.2). */
enum VerificationStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
