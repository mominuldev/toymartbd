<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Orders;

use App\Domain\Finance\Actions\RecognizeOrderRevenue;
use App\Domain\Ordering\Events\OrderDelivered;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * OrderDelivered → revenue, discount, shipping revenue and COGS on the ledger (§4.3).
 * Queued on `critical` after the delivery commits; a replay or retry is a no-op because
 * the ledger is idempotent per (source, type). A cancellation before delivery has no
 * listener here on purpose: nothing was recognised, so nothing is reversed.
 */
final readonly class RecognizeRevenueOnDelivery implements ShouldQueue
{
    public function __construct(private RecognizeOrderRevenue $recognize) {}

    public function viaQueue(): string
    {
        return 'critical';
    }

    public function handle(OrderDelivered $event): void
    {
        $this->recognize->handle($event->orderId);
    }
}
