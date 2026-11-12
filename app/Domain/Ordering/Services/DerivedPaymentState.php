<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Support\Money;

final readonly class DerivedPaymentState
{
    public function __construct(
        public OrderPaymentStatus $status,
        public Money $paid,
        public Money $refunded,
    ) {}
}
