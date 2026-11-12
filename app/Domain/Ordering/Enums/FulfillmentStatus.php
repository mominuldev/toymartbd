<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/**
 * `orders.fulfillment_status` — the physical goods (DATABASE-DESIGN §3.4). The column
 * keeps the design's spelling; the domain is "Fulfilment".
 */
enum FulfillmentStatus: string
{
    case Unfulfilled = 'unfulfilled';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Returned = 'returned';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Unfulfilled => [self::PartiallyFulfilled, self::Fulfilled],
            self::PartiallyFulfilled => [self::Fulfilled, self::Returned],
            self::Fulfilled => [self::Returned],
            self::Returned => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
