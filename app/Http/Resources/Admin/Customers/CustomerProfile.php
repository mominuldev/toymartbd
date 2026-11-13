<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Customers;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Models\CustomerRiskProfile;
use Illuminate\Support\Collection;

/** What the customer profile endpoint composes before {@see CustomerProfileResource} shapes it. */
final readonly class CustomerProfile
{
    /**
     * @param  array<string, int>  $statusCounts  order status → count
     * @param  array{lifetime_value: \App\Support\Money, delivered_revenue: \App\Support\Money, refunds: \App\Support\Money}  $ledger
     * @param  Collection<int, Order>  $orders
     */
    public function __construct(
        public Customer $customer,
        public array $statusCounts,
        public array $ledger,
        public string $segment,
        public Collection $orders,
        public ?CustomerRiskProfile $risk,
        public int $notesCount,
        public ?CustomerNote $pinnedNote,
    ) {}
}
