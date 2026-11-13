<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/** `cart_recoveries.status` (P7). A recovered cart keeps `messaged` and gains `recovered_at`. */
enum CartRecoveryStatus: string
{
    /** A reminder was queued (its notification log row says whether it was delivered). */
    case Messaged = 'messaged';
    /** Abandoned but not messaged; `skip_reason` says why. */
    case Skipped = 'skipped';
}
