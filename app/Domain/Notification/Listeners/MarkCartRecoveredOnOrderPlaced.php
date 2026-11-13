<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\MarkCartRecovered;
use App\Domain\Ordering\Events\OrderPlaced;

/** OrderPlaced (after commit) → a reminded cart that became an order is recovered (P7). */
final readonly class MarkCartRecoveredOnOrderPlaced
{
    public function __construct(private MarkCartRecovered $mark) {}

    public function handle(OrderPlaced $event): void
    {
        $this->mark->handle($event->orderId);
    }
}
