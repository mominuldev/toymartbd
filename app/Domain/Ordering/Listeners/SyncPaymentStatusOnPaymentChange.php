<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Listeners;

use App\Domain\Ordering\Actions\SyncOrderPaymentStatus;
use App\Domain\Payment\Events\PaymentStatusChanged;

/**
 * Payment → Ordering, through an event (rule 2): Payment never writes to orders. Runs
 * synchronously after the payment's transaction commits.
 */
final readonly class SyncPaymentStatusOnPaymentChange
{
    public function __construct(private SyncOrderPaymentStatus $sync) {}

    public function handle(PaymentStatusChanged $event): void
    {
        $this->sync->handle($event->orderId);
    }
}
