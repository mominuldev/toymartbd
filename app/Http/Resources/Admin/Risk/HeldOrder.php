<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Risk;

use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Models\CustomerRiskProfile;

/** One verification-queue row: the held order and its phone's profile (null = none yet). */
final readonly class HeldOrder
{
    public function __construct(
        public Order $order,
        public ?CustomerRiskProfile $profile,
        public int $callCount,
        public ?string $lastCallOutcome,
    ) {}

    /**
     * The queue is cursor-paginated on the order's (placed_at, id), and the paginator reads those
     * columns off each row to build `next_cursor`. Without these, a queue longer than one page
     * failed with a 500 (found under volume, Phase 9 — docs/audits/PERFORMANCE.md P-09).
     */
    public function __get(string $name): mixed
    {
        return $this->order->getAttribute($name);
    }

    public function __isset(string $name): bool
    {
        return $this->order->getAttribute($name) !== null;
    }
}
