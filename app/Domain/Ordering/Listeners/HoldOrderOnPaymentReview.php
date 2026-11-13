<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Listeners;

use App\Domain\Ordering\Actions\HoldOrderForPaymentReview;
use App\Domain\Payment\Events\PaymentFlaggedForReview;

/** Payment → Ordering through an event (rule 2): a flagged payment holds its order. */
final readonly class HoldOrderOnPaymentReview
{
    public function __construct(private HoldOrderForPaymentReview $hold) {}

    public function handle(PaymentFlaggedForReview $event): void
    {
        $this->hold->handle($event->orderId, $event->paymentId, $event->reason);
    }
}
