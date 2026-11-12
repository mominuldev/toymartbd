<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order's lines, address or delivery charge changed after placement (P4-ORD-07,
 * EditOrder). After commit. `previousTotal` / `newTotal` are decimal strings; the full
 * before/after lives on the `edited` order_events row.
 */
final readonly class OrderEdited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  list<string>  $changed  which parts changed: lines|address|recipient|totals */
    public function __construct(
        public int $orderId,
        public int $customerId,
        public array $changed,
        public string $previousTotal,
        public string $newTotal,
        public ?int $userId = null,
    ) {}
}
