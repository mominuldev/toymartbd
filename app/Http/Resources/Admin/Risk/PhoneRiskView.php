<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Risk;

use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\DTOs\RiskBreakdown;
use App\Domain\Risk\Models\CustomerRiskProfile;
use Illuminate\Support\Collection;

/**
 * Everything the phone lookup shows about one phone. `courier` is the Fulfilment
 * CourierHistoryLookup answer, kept apart from our profile all the way to the screen (rule 24).
 */
final readonly class PhoneRiskView
{
    /**
     * @param  list<array<string, mixed>>  $courier
     * @param  Collection<int, Order>  $recentOrders
     * @param  array{total_orders: int, delivered: int, cancelled: int, returned: int, failed_delivery: int, refused: int}|null  $previewCounts
     */
    public function __construct(
        public string $phone,
        public ?CustomerRiskProfile $profile,
        public ?RiskBreakdown $preview,
        public ?array $previewCounts,
        public array $courier,
        public string $courierCheckedAt,
        public Collection $recentOrders,
    ) {}
}
