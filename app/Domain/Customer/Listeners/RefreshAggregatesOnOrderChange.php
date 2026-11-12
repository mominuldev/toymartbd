<?php

declare(strict_types=1);

namespace App\Domain\Customer\Listeners;

use App\Domain\Customer\Actions\RefreshCustomerAggregates;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Keeps the customer aggregate projection current (P4-ORD-10): queued (DATABASE-DESIGN
 * §3.3), on every order placement and every status transition. A full recomputation each
 * time, so duplicate or reordered deliveries are harmless.
 */
final readonly class RefreshAggregatesOnOrderChange implements ShouldQueue
{
    public function __construct(private RefreshCustomerAggregates $refresh) {}

    public function viaQueue(): string
    {
        return 'reports';
    }

    /** Registered for OrderPlaced and the OrderStatusTransition interface (every OrderStatusChanged). */
    public function handle(OrderPlaced|OrderStatusChanged $event): void
    {
        $this->refresh->handle($event->customerId);
    }
}
