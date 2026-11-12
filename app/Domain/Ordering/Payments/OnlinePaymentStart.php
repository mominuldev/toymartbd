<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Payments;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;

/** What StartOnlinePayment produced: where to send the customer, or why not. */
final readonly class OnlinePaymentStart
{
    public function __construct(
        public Payment $payment,
        public ?string $redirectUrl,
        public bool $resumed = false,
    ) {}

    public function paid(): bool
    {
        return $this->payment->status === PaymentStatus::Paid;
    }
}
