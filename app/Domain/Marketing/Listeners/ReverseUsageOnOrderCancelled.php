<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Listeners;

use App\Domain\Marketing\Actions\ReverseOrderMarketingUsage;
use App\Domain\Ordering\Events\OrderCancelled;

/** OrderCancelled (after commit) → give back the order's coupon and promotion usage. */
final readonly class ReverseUsageOnOrderCancelled
{
    public function __construct(private ReverseOrderMarketingUsage $reverse) {}

    public function handle(OrderCancelled $event): void
    {
        $this->reverse->handle($event->orderId);
    }
}
