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
}
