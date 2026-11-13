<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A partly received PO was closed short: the supplier will not send the rest. After commit. */
final readonly class PurchaseClosedShort implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $purchaseId,
        public int $unitsNotReceived,
        public string $reason,
        public ?int $userId,
    ) {}
}
