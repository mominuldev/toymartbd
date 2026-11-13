<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A PO moved state (ordered, cancelled, partially received, received). After commit. */
final readonly class PurchaseStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $purchaseId,
        public string $from,
        public string $to,
    ) {}
}
